@extends('layouts.erp')

@section('title', 'Salary payment & files '.$run->label())

@php($p = fn ($paise) => \App\Support\Money::format($paise, paiseInput: true))
@php($r = fn ($rupees) => number_format($rupees))
@php($posted = $run->status === 'posted')
@php($export = auth()->user()->can('reports.export'))

@section('content')
<div class="page-header">
    <div>
        <h1>Salary payment &amp; files</h1>
        <p class="text-secondary mb-0">Payroll {{ $run->label() }} · net pay {{ \App\Support\Money::format($run->net_total) }}</p>
    </div>
    <a href="{{ route('payroll-runs.show', $run) }}" class="btn btn-outline-secondary">Pay sheet</a>
</div>

@unless ($posted)
    <div class="alert alert-warning">Post the payroll first. The payment and the files are made from posted payroll only.</div>
@endunless

{{-- Salary payment --}}
<div class="erp-card mb-3">
    <div class="erp-card-header">Salary payment</div>
    <div class="p-3">
        @if ($run->paymentVoucher && ! $run->paymentVoucher->isCancelled())
            <p class="mb-0"><i class="bi bi-check-circle text-success"></i> Paid by payment voucher <a href="{{ route('vouchers.show', $run->paymentVoucher) }}">{{ $run->paymentVoucher->voucher_number }}</a> dated {{ $run->paymentVoucher->date->format('d M Y') }}: Salary Payable debited, {{ $run->paymentVoucher->entries()->where('credit', '>', 0)->first()?->account?->name }} credited with {{ \App\Support\Money::format($run->net_total) }}.</p>
        @elseif ($posted && auth()->user()->can('payroll.edit') && (float) $run->net_total > 0)
            <p class="small text-secondary">Records the transfer of net pay: debits Salary Payable and credits the bank or cash ledger with {{ \App\Support\Money::format($run->net_total) }}.</p>
            <form method="POST" action="{{ route('payroll-runs.pay', $run) }}" class="row g-2 align-items-end">
                @csrf
                <div class="col-6 col-md-3">
                    <label class="form-label small" for="pay-date">Date</label>
                    <input id="pay-date" type="date" name="date" value="{{ old('date', $run->date->toDateString()) }}" class="form-control form-control-sm @error('date') is-invalid @enderror" required>
                    @error('date')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-6 col-md-4">
                    <label class="form-label small" for="paid-from">Paid from</label>
                    <select id="paid-from" name="paid_account_id" class="form-select form-select-sm @error('paid_account_id') is-invalid @enderror" required>
                        <option value="">Choose…</option>
                        @foreach ($paidFrom as $a)<option value="{{ $a->id }}" @selected((int) old('paid_account_id', $paidFrom->firstWhere('account_type', 'bank')?->id) === $a->id)>{{ $a->name }}</option>@endforeach
                    </select>
                    @error('paid_account_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-12 col-md-auto"><button class="btn btn-sm btn-primary" onclick="return confirm('Post the salary payment voucher?')">Record payment</button></div>
            </form>
        @else
            <p class="mb-0 text-secondary">Not paid yet.</p>
        @endif
    </div>
</div>

{{-- Bank transfer --}}
<div class="erp-card mb-3">
    <div class="erp-card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
        <span>Bank transfer list <span class="text-secondary small">{{ $bank['rows']->count() }} · {{ $p($bank['total']) }}</span></span>
        @if ($posted && $export && $bank['rows']->isNotEmpty())
            <span class="d-flex gap-2">
                <a href="{{ route('payroll-runs.bank-file', $run) }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-filetype-csv"></i> CSV</a>
                <a href="{{ route('payroll-runs.bank-file', [$run, 'export' => 'xlsx']) }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-file-earmark-excel"></i> Excel</a>
            </span>
        @endif
    </div>
    <div class="table-responsive">
        <table class="table table-sm align-middle mb-0 table-stack">
            <thead class="table-light"><tr><th>Employee</th><th>Bank</th><th>Account number</th><th>IFSC</th><th class="text-end">Net pay</th></tr></thead>
            <tbody>
            @forelse ($bank['rows'] as $row)
                <tr>
                    <td data-label="Employee">{{ $row->name }} <span class="text-secondary small">{{ $row->code }}</span></td>
                    <td data-label="Bank">{{ $row->bank ?: '—' }}</td>
                    <td data-label="Account">{{ $row->account }}</td>
                    <td data-label="IFSC">{{ $row->ifsc }}</td>
                    <td data-label="Net pay" class="amount">{{ $p($row->amount) }}</td>
                </tr>
            @empty
                <tr><td colspan="5" class="text-secondary text-center py-3">No employee has net pay and complete bank details.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    @include('payroll.runs._skipped', ['skipped' => $bank['skipped'], 'what' => 'net pay', 'fix' => 'bank account and IFSC'])
</div>

{{-- PF ECR --}}
<div class="erp-card mb-3">
    <div class="erp-card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
        <span>PF electronic challan cum return (ECR) <span class="text-secondary small">{{ $ecr['rows']->count() }} {{ \Illuminate\Support\Str::plural('member', $ecr['rows']->count()) }}</span></span>
        @if ($posted && $export && $ecr['rows']->isNotEmpty())
            <a href="{{ route('payroll-runs.ecr', $run) }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-filetype-txt"></i> ECR text file</a>
        @endif
    </div>
    <div class="table-responsive">
        <table class="table table-sm align-middle mb-0 table-stack table-report">
            <thead class="table-light"><tr><th>UAN</th><th>Member</th><th class="text-end">Gross wages</th><th class="text-end">EPF wages</th><th class="text-end">EPS wages</th><th class="text-end">EDLI wages</th><th class="text-end">EE share</th><th class="text-end">EPS</th><th class="text-end">ER EPF</th><th class="text-end">NCP days</th></tr></thead>
            <tbody>
            @forelse ($ecr['rows'] as $row)
                <tr>
                    <td data-label="UAN">{{ $row->uan }}</td>
                    <td data-label="Member">{{ $row->name }}</td>
                    <td data-label="Gross wages" class="amount">{{ $r($row->gross) }}</td>
                    <td data-label="EPF wages" class="amount">{{ $r($row->epf_wages) }}</td>
                    <td data-label="EPS wages" class="amount">{{ $r($row->eps_wages) }}</td>
                    <td data-label="EDLI wages" class="amount">{{ $r($row->edli_wages) }}</td>
                    <td data-label="Employee share" class="amount">{{ $r($row->epf) }}</td>
                    <td data-label="EPS" class="amount">{{ $r($row->eps) }}</td>
                    <td data-label="Employer EPF" class="amount">{{ $r($row->diff) }}</td>
                    <td data-label="NCP days" class="text-end">{{ $row->ncp }}</td>
                </tr>
            @empty
                <tr><td colspan="10" class="text-secondary text-center py-3">No PF in this payroll for employees with a UAN.</td></tr>
            @endforelse
            </tbody>
            @if ($ecr['rows']->isNotEmpty())
                <tfoot><tr class="row-total">
                    <td colspan="2">Total</td>
                    @foreach (['gross' => 'Gross wages', 'epf_wages' => 'EPF wages', 'eps_wages' => 'EPS wages', 'edli_wages' => 'EDLI wages', 'epf' => 'Employee share', 'eps' => 'EPS', 'diff' => 'Employer EPF'] as $k => $label)
                        <td class="amount" data-label="{{ $label }}">{{ $r($ecr['totals'][$k]) }}</td>
                    @endforeach
                    <td class="text-end" data-label="NCP days">{{ $ecr['totals']['ncp'] }}</td>
                </tr></tfoot>
            @endif
        </table>
    </div>
    @include('payroll.runs._skipped', ['skipped' => $ecr['skipped'], 'what' => 'employee PF', 'fix' => 'UAN'])
    <p class="small text-secondary px-3 pb-3 mb-0">Whole rupees. EPS is 8.33% of wages up to {{ number_format(config('erp.payroll.pf_wage_ceiling')) }}, nil from the month a member turns {{ \App\Services\Payroll\PayrollFiles::EPS_AGE_LIMIT }}; the rest of the employer's share goes to EPF. Upload the text file in the EPFO employer portal under ECR upload (version 2).</p>
</div>

{{-- ESI --}}
<div class="erp-card mb-3">
    <div class="erp-card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
        <span>ESI monthly contribution <span class="text-secondary small">{{ $esi['rows']->count() }} {{ \Illuminate\Support\Str::plural('employee', $esi['rows']->count()) }}</span></span>
        @if ($posted && $export && $esi['rows']->isNotEmpty())
            <a href="{{ route('payroll-runs.esi-file', $run) }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-file-earmark-excel"></i> Excel for ESIC</a>
        @endif
    </div>
    <div class="table-responsive">
        <table class="table table-sm align-middle mb-0 table-stack">
            <thead class="table-light"><tr><th>IP number</th><th>Name</th><th class="text-end">Days paid</th><th class="text-end">Wages</th><th class="text-end">Employee</th><th class="text-end">Employer</th></tr></thead>
            <tbody>
            @forelse ($esi['rows'] as $row)
                <tr>
                    <td data-label="IP number">{{ $row->ip }}</td>
                    <td data-label="Name">{{ $row->name }}</td>
                    <td data-label="Days paid" class="text-end">{{ $row->days }}</td>
                    <td data-label="Wages" class="amount">{{ $r($row->wages) }}</td>
                    <td data-label="Employee" class="amount">{{ $p($row->employee) }}</td>
                    <td data-label="Employer" class="amount">{{ $p($row->employer) }}</td>
                </tr>
            @empty
                <tr><td colspan="6" class="text-secondary text-center py-3">No ESI in this payroll for employees with an ESI number.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    @include('payroll.runs._skipped', ['skipped' => $esi['skipped'], 'what' => 'employee ESI', 'fix' => 'ESI number'])
</div>
@endsection
