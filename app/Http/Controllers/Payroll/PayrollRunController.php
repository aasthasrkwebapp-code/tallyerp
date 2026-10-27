<?php

namespace App\Http\Controllers\Payroll;

use App\Http\Controllers\Controller;
use App\Models\PayHead;
use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Services\Payroll\PayrollService;
use App\Support\CsvExport;
use App\Support\Decimal;
use App\Support\Money;
use Illuminate\Http\Request;

class PayrollRunController extends Controller
{
    public function __construct(protected PayrollService $payroll) {}

    public function index()
    {
        return view('payroll.runs.index', [
            'runs' => PayrollRun::query()->withCount('payslips')->with('voucher')->orderByDesc('month')->orderByDesc('id')->paginate(24),
            'suggested' => now()->subMonthNoOverflow()->format('Y-m'),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate(['month' => ['required', 'string']]);
        $run = $this->payroll->prepare($data['month'], $request->user());

        return redirect()->route('payroll-runs.show', $run)->with('success', "Payroll for {$run->label()} prepared as a draft. Check paid days, then post.");
    }

    /** The pay sheet: one row per employee, one column per pay head. */
    public function show(Request $request, PayrollRun $payrollRun)
    {
        $payrollRun->load('payslips.lines', 'voucher', 'creator');
        $headIds = $payrollRun->payslips->flatMap->lines->pluck('pay_head_id')->unique();
        $heads = PayHead::query()->whereIn('id', $headIds)->orderBy('sort_order')->orderBy('id')->get();
        $amounts = $payrollRun->payslips->mapWithKeys(fn ($s) => [$s->id => $s->lines->keyBy('pay_head_id')]);

        if (\App\Support\CsvExport::requested($request)) {
            $this->authorize('payroll.view');
            $header = array_merge(['Code', 'Employee', 'Paid days'], $heads->pluck('name')->all(), ['Gross', 'Deductions', 'Net pay', 'Employer contributions']);
            $rows = $payrollRun->payslips->sortBy('employee_code')->map(fn ($s) => array_merge(
                [$s->employee_code, $s->employee_name, Decimal::trim($s->paid_days)],
                $heads->map(fn ($h) => $amounts[$s->id]->get($h->id)?->amount ?? '0.00')->all(),
                [$s->gross, $s->deductions, $s->net, $s->employer_contributions],
            ));

            return CsvExport::download("pay-sheet-{$payrollRun->month}.csv", $header, $rows->values());
        }

        $headTotals = $heads->mapWithKeys(fn ($h) => [$h->id => $payrollRun->payslips->sum(fn ($s) => Money::toPaise($amounts[$s->id]->get($h->id)?->amount ?? 0))]);

        return view('payroll.runs.show', [
            'run' => $payrollRun,
            'heads' => $heads,
            'amounts' => $amounts,
            'headTotals' => $headTotals,
            'slips' => $payrollRun->payslips->sortBy('employee_code')->values(),
        ]);
    }

    public function update(Request $request, PayrollRun $payrollRun)
    {
        $data = $request->validate(['paid_days' => ['nullable', 'array'], 'paid_days.*' => ['nullable', 'string', 'max:5']]);
        $this->payroll->recalculate($payrollRun, $data['paid_days'] ?? []);

        return back()->with('success', 'Payroll recalculated.');
    }

    public function post(Request $request, PayrollRun $payrollRun)
    {
        $data = $request->validate(['date' => ['nullable', 'date']]);
        $run = $this->payroll->post($payrollRun, $request->user(), $data['date'] ?? null);

        return redirect()->route('payroll-runs.show', $run)->with('success', "Payroll for {$run->label()} posted as journal {$run->voucher->voucher_number}.");
    }

    public function cancel(Request $request, PayrollRun $payrollRun)
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);
        $this->payroll->cancel($payrollRun, $request->user(), $data['reason']);

        return back()->with('success', "Payroll for {$payrollRun->label()} cancelled.");
    }

    public function destroy(PayrollRun $payrollRun)
    {
        $label = $payrollRun->label();
        $this->payroll->delete($payrollRun);

        return redirect()->route('payroll-runs.index')->with('success', "Draft payroll for {$label} deleted.");
    }

    public function payslip(PayrollRun $payrollRun, Payslip $payslip)
    {
        abort_unless($payslip->payroll_run_id === $payrollRun->id, 404);
        $payslip->load('lines', 'employee');

        if (request()->input('export') === 'pdf') {
            $name = \Illuminate\Support\Str::slug('payslip '.$payrollRun->label().' '.$payslip->employee_code).'.pdf';

            return \Barryvdh\DomPDF\Facade\Pdf::loadView('pdf.payslip', ['run' => $payrollRun, 'slip' => $payslip, 'company' => app(\App\Support\CurrentCompany::class)->get()])
                ->setPaper('a4')->download($name);
        }

        return view('payroll.runs.payslip', ['run' => $payrollRun, 'slip' => $payslip]);
    }
}
