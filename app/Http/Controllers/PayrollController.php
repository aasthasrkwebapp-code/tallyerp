<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Models\Voucher;
use App\Support\Books;
use App\Support\Payroll;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Monthly payroll: calculate payslips and post the salary journal.
 */
class PayrollController extends Controller
{
    public function index(Company $company)
    {
        $this->authorizeCompany($company);

        $runs = $company->payrollRuns()->withCount('payslips')->with('payslips')->orderByDesc('month')->get();

        return view('payroll.index', compact('company', 'runs'));
    }

    public function create(Request $request, Company $company)
    {
        $this->authorizeCompany($company);

        $request->validate(['month' => ['nullable', 'date_format:Y-m']]);
        $month = $request->filled('month')
            ? CarbonImmutable::createFromFormat('Y-m-d', $request->input('month').'-01')
            : CarbonImmutable::now()->startOfMonth();

        $employees = $company->employees()->where('is_active', true)->orderBy('name')->get();

        return view('payroll.create', compact('company', 'month', 'employees'));
    }

    public function store(Request $request, Company $company)
    {
        $this->authorizeCompany($company);

        $data = $request->validate([
            'month' => ['required', 'date_format:Y-m'],
            'days' => ['required', 'array', 'min:1'],
            'days.*' => ['nullable', 'numeric', 'min:0', 'max:31'],
        ]);

        $month = CarbonImmutable::createFromFormat('Y-m-d', $data['month'].'-01')->startOfDay();
        $postingDate = $month->endOfMonth()->startOfDay();
        $daysInMonth = $month->daysInMonth;

        [$start, $end] = (new Books($company))->financialYear();
        if ($postingDate->lt($start) || $postingDate->gt($end)) {
            throw ValidationException::withMessages(['month' => 'The month must fall within financial year '.$company->financial_year.'.']);
        }
        if ($company->payrollRuns()->whereDate('month', $month->toDateString())->exists()) {
            throw ValidationException::withMessages(['month' => 'Payroll for '.$month->format('F Y').' has already been run.']);
        }

        $employees = $company->employees()->whereKey(array_keys($data['days']))->where('is_active', true)->get()->keyBy('id');
        $lines = collect($data['days'])
            ->filter(fn ($days, $id) => $employees->has($id) && (float) $days > 0)
            ->map(fn ($days, $id) => ['employee_id' => (int) $id] + Payroll::calculate($employees[$id], (float) $days, $daysInMonth));

        if ($lines->isEmpty()) {
            throw ValidationException::withMessages(['days' => 'Enter payable days for at least one employee.']);
        }

        $run = DB::transaction(function () use ($company, $month, $postingDate, $lines) {
            $sum = fn (string $field) => round($lines->sum($field), 2);

            $voucher = $company->vouchers()->create([
                'type' => 'journal',
                'number' => Voucher::nextNumber($company, 'journal'),
                'date' => $postingDate->toDateString(),
                'reference' => 'Payroll '.$month->format('M Y'),
                'narration' => 'Salary for '.$month->format('F Y'),
                'amount' => round($sum('gross') + $sum('pf_employer') + $sum('esi_employer'), 2),
            ]);

            $ledger = fn (string $code, string $name, string $group) => $company->systemLedger($code, $name, $group)->id;
            $entries = [
                [$ledger('PAY_SALARIES', 'Salaries', 'INDIRECT_EXPENSES'), $sum('gross'), 0],
                [$ledger('PAY_EMPLOYER_CONTRIB', 'Employer PF & ESI Contribution', 'INDIRECT_EXPENSES'), round($sum('pf_employer') + $sum('esi_employer'), 2), 0],
                [$ledger('PAY_SALARY_PAYABLE', 'Salary Payable', 'CURRENT_LIABILITIES'), 0, $sum('net_pay')],
                [$ledger('PAY_PF_PAYABLE', 'PF Payable', 'CURRENT_LIABILITIES'), 0, round($sum('pf_employee') + $sum('pf_employer'), 2)],
                [$ledger('PAY_ESI_PAYABLE', 'ESI Payable', 'CURRENT_LIABILITIES'), 0, round($sum('esi_employee') + $sum('esi_employer'), 2)],
                [$ledger('PAY_PT_PAYABLE', 'Professional Tax Payable', 'DUTIES_TAXES'), 0, $sum('professional_tax')],
            ];

            $voucher->entries()->createMany(
                collect($entries)
                    ->filter(fn ($e) => $e[1] > 0 || $e[2] > 0)
                    ->map(fn ($e) => ['account_id' => $e[0], 'debit' => $e[1], 'credit' => $e[2]])
                    ->values()
                    ->all()
            );

            $run = $company->payrollRuns()->create(['month' => $month->toDateString(), 'voucher_id' => $voucher->id]);
            $run->payslips()->createMany($lines->values()->all());

            return $run;
        });

        return redirect()
            ->route('companies.payroll.show', [$company, $run])
            ->with('success', 'Payroll for '.$month->format('F Y').' posted.');
    }

    public function show(Company $company, PayrollRun $payroll)
    {
        $this->authorizeCompany($company);
        abort_unless((int) $payroll->company_id === (int) $company->id, 404);

        $payroll->load('payslips.employee', 'voucher');

        return view('payroll.show', ['company' => $company, 'run' => $payroll]);
    }

    public function payslip(Company $company, PayrollRun $payroll, Payslip $payslip)
    {
        $this->authorizeCompany($company);
        abort_unless((int) $payroll->company_id === (int) $company->id && (int) $payslip->payroll_run_id === (int) $payroll->id, 404);

        $payslip->load('employee');

        return view('payroll.payslip', ['company' => $company, 'run' => $payroll, 'payslip' => $payslip]);
    }

    public function destroy(Company $company, PayrollRun $payroll)
    {
        $this->authorizeCompany($company);
        abort_unless((int) $payroll->company_id === (int) $company->id, 404);

        DB::transaction(function () use ($payroll) {
            $voucher = $payroll->voucher;
            $payroll->delete();
            $voucher?->delete();
        });

        return redirect()
            ->route('companies.payroll.index', $company)
            ->with('success', 'Payroll run deleted.');
    }
}
