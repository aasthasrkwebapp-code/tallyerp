<?php

namespace App\Http\Controllers\Payroll;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Employee;
use App\Models\EmployeeLoan;
use App\Services\Payroll\LoanService;
use App\Support\CsvExport;
use App\Support\Money;
use Illuminate\Http\Request;

class EmployeeLoanController extends Controller
{
    public function __construct(protected LoanService $loans) {}

    public function index(Request $request)
    {
        $status = $request->input('status', 'outstanding');
        $query = EmployeeLoan::query()->with('employee')->orderByDesc('date')->orderByDesc('id');
        if ($request->filled('employee_id')) {
            $query->where('employee_id', $request->integer('employee_id'));
        }
        $rows = $this->loans->summary($query->get());
        $rows = match ($status) {
            'outstanding' => $rows->filter(fn ($r) => $r->outstanding > 0),
            'repaid' => $rows->filter(fn ($r) => $r->loan->status === 'active' && $r->outstanding <= 0),
            'cancelled' => $rows->filter(fn ($r) => $r->loan->status === 'cancelled'),
            default => $rows,
        };

        if (CsvExport::requested($request)) {
            return CsvExport::download('employee-loans.csv', ['Date', 'Employee code', 'Employee', 'Type', 'Amount', 'Monthly instalment', 'Recover from', 'Recovered', 'Outstanding', 'Status'],
                $rows->map(fn ($r) => [
                    $r->loan->date->toDateString(), $r->loan->employee->code, $r->loan->employee->name, EmployeeLoan::KINDS[$r->loan->kind],
                    $r->loan->amount, $r->loan->installment, $r->loan->recover_from, Money::fromPaise($r->recovered), Money::fromPaise($r->outstanding), $this->statusLabel($r),
                ])->values());
        }

        return view('payroll.loans.index', [
            'rows' => $rows->values(),
            'status' => $status,
            'employees' => Employee::query()->orderBy('name')->get(['id', 'name', 'code']),
            'totals' => ['amount' => $rows->sum('amount'), 'recovered' => $rows->sum('recovered'), 'outstanding' => $rows->sum('outstanding')],
        ]);
    }

    public function create(Request $request)
    {
        return view('payroll.loans.form', [
            'employees' => Employee::query()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'code']),
            'loanLedgers' => $this->loans->loanLedgerQuery()->orderBy('name')->get(),
            'paidFrom' => $this->loans->paidFromQuery()->orderBy('name')->get(),
            'defaultLedger' => Account::idFor('salary_advances'),
            'employeeId' => $request->integer('employee_id') ?: null,
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'employee_id' => ['required', 'integer'],
            'kind' => ['required', 'string'],
            'date' => ['required', 'date_format:Y-m-d'],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:999999999.99', 'decimal:0,2'],
            'installment' => ['required', 'numeric', 'min:0.01', 'max:999999999.99', 'decimal:0,2'],
            'recover_from' => ['required', 'string'],
            'account_id' => ['nullable', 'integer'],
            'paid_account_id' => ['required', 'integer'],
            'description' => ['nullable', 'string', 'max:255'],
        ]);
        $loan = $this->loans->create($data, $request->user());

        return redirect()->route('employee-loans.show', $loan)->with('success', EmployeeLoan::KINDS[$loan->kind]." recorded and paid as voucher {$loan->voucher->voucher_number}.");
    }

    public function show(EmployeeLoan $employeeLoan)
    {
        $employeeLoan->load('employee', 'account', 'paidAccount', 'voucher', 'recoveries.payslip.run');
        $balance = Money::toPaise($employeeLoan->amount);
        $schedule = $employeeLoan->recoveries->sortBy(fn ($r) => $r->payslip->run->month)->values()->map(function ($r) use (&$balance) {
            $counts = $r->payslip->run->status === 'posted';
            if ($counts) {
                $balance -= Money::toPaise($r->amount);
            }

            return (object) ['recovery' => $r, 'run' => $r->payslip->run, 'counts' => $counts, 'balance' => $balance];
        });

        return view('payroll.loans.show', [
            'loan' => $employeeLoan,
            'summary' => $this->loans->summary(collect([$employeeLoan]))->first(),
            'schedule' => $schedule,
        ]);
    }

    public function cancel(Request $request, EmployeeLoan $employeeLoan)
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);
        $this->loans->cancel($employeeLoan, $request->user(), $data['reason']);

        return back()->with('success', EmployeeLoan::KINDS[$employeeLoan->kind].' cancelled, with its payment voucher.');
    }

    protected function statusLabel(object $r): string
    {
        return $r->loan->status === 'cancelled' ? 'Cancelled' : ($r->outstanding > 0 ? 'Outstanding' : 'Repaid');
    }
}
