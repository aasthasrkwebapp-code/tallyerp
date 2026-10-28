<?php

namespace App\Http\Controllers\Payroll;

use App\Http\Controllers\Controller;
use App\Models\PayrollRun;
use App\Services\Payroll\PayrollFiles;
use App\Services\Payroll\PayrollService;
use App\Support\CsvExport;
use App\Support\Money;
use Illuminate\Http\Request;

/** Salary payment and the bank, PF (ECR) and ESI files of a posted payroll run. */
class PayrollFileController extends Controller
{
    public function __construct(protected PayrollFiles $files, protected PayrollService $payroll) {}

    public function index(PayrollRun $payrollRun)
    {
        $payrollRun->load('voucher', 'paymentVoucher');

        return view('payroll.runs.files', [
            'run' => $payrollRun,
            'bank' => $this->files->bankTransfer($payrollRun),
            'ecr' => $this->files->ecr($payrollRun),
            'esi' => $this->files->esi($payrollRun),
            'paidFrom' => $this->payroll->paidFromQuery()->orderBy('name')->get(),
        ]);
    }

    public function bank(Request $request, PayrollRun $payrollRun)
    {
        $this->authorize('reports.export');
        if ($redirect = $this->unposted($payrollRun)) {
            return $redirect;
        }
        $bank = $this->files->bankTransfer($payrollRun);
        if ($bank['rows']->isEmpty()) {
            return back()->with('error', 'No employee has net pay and complete bank details.');
        }
        $narration = 'Salary '.$payrollRun->monthStart()->format('M Y');
        $request->merge(['export' => $request->input('export') === 'xlsx' ? 'xlsx' : 'csv']);

        return CsvExport::download("salary-bank-transfer-{$payrollRun->month}.csv",
            ['Sr no', 'Employee code', 'Beneficiary name', 'Bank', 'Account number', 'IFSC', 'Amount', 'Narration'],
            $bank['rows']->values()->map(fn ($r, $i) => [$i + 1, $r->code, $r->name, $r->bank, $r->account, $r->ifsc, Money::fromPaise($r->amount), $narration])
                ->push(['', '', 'Total', '', '', '', Money::fromPaise($bank['total']), '']));
    }

    public function ecr(Request $request, PayrollRun $payrollRun)
    {
        $this->authorize('reports.export');
        if ($redirect = $this->unposted($payrollRun)) {
            return $redirect;
        }
        $ecr = $this->files->ecr($payrollRun);
        if ($ecr['rows']->isEmpty()) {
            return back()->with('error', 'No employee with a UAN has PF in this payroll.');
        }
        $text = $this->files->ecrText($ecr);

        return response()->streamDownload(fn () => print ($text), "ECR_{$payrollRun->month}.txt", ['Content-Type' => 'text/plain; charset=UTF-8']);
    }

    public function esi(Request $request, PayrollRun $payrollRun)
    {
        $this->authorize('reports.export');
        if ($redirect = $this->unposted($payrollRun)) {
            return $redirect;
        }
        $esi = $this->files->esi($payrollRun);
        if ($esi['rows']->isEmpty()) {
            return back()->with('error', 'No employee with an ESI number has ESI in this payroll.');
        }
        $request->merge(['export' => $request->input('export') === 'csv' ? 'csv' : 'xlsx']);

        return CsvExport::download("esi-contribution-{$payrollRun->month}.csv",
            ['IP Number', 'IP Name', 'No of Days for which wages paid/payable during the month', 'Total Monthly Wages', 'Reason Code for Zero workings days', 'Last Working Day'],
            $esi['rows']->map(fn ($r) => [$r->ip, $r->name, $r->days, $r->wages, $r->reason, '']));
    }

    public function pay(Request $request, PayrollRun $payrollRun)
    {
        $data = $request->validate([
            'date' => ['required', 'date'],
            'paid_account_id' => ['required', 'integer'],
        ]);
        $this->payroll->pay($payrollRun, $data, $request->user());

        return redirect()->route('payroll-runs.files', $payrollRun)->with('success', "Salary of {$payrollRun->label()} recorded as paid.");
    }

    protected function unposted(PayrollRun $run)
    {
        return $run->status === 'posted' ? null : redirect()->route('payroll-runs.files', $run)->with('error', 'Post the payroll before making its files.');
    }
}
