@extends('layouts.erp')

@section('title', 'Payslip')

@php($m = fn ($v) => \App\Support\Money::format($v))
@php($e = $slip->employee)

@section('content')
<div class="page-header no-print">
    <h1>Payslip</h1>
    <div class="d-flex gap-2">
        <a href="{{ route('payroll-runs.show', $run) }}" class="btn btn-outline-secondary">Pay sheet</a>
        <a href="{{ request()->fullUrlWithQuery(['export' => 'pdf']) }}" class="btn btn-outline-secondary"><i class="bi bi-file-earmark-pdf"></i> PDF</a>
        <button type="button" class="btn btn-primary" onclick="window.print()"><i class="bi bi-printer"></i> Print</button>
    </div>
</div>

<div class="erp-card p-3 p-md-4" style="max-width: 820px">
    <div class="text-center mb-3">
        <div class="fs-5 fw-bold">{{ $currentCompany->displayName() }}</div>
        <div class="small text-secondary">{{ $currentCompany->address ?? '' }}</div>
        <div class="fw-semibold mt-2">Payslip for {{ $run->label() }} @if ($run->isDraft())<span class="badge text-bg-warning">Draft</span>@endif</div>
    </div>
    <div class="row small g-2 mb-3">
        <div class="col-6 col-md-4"><span class="text-secondary">Employee</span><br>{{ $slip->employee_name }} ({{ $slip->employee_code }})</div>
        <div class="col-6 col-md-4"><span class="text-secondary">Designation</span><br>{{ $slip->designation ?: '—' }}</div>
        <div class="col-6 col-md-4"><span class="text-secondary">Paid days</span><br>{{ \App\Support\Decimal::trim($slip->paid_days) }} of {{ $slip->days_in_month }}@if ((float) $slip->overtime_hours > 0) · overtime {{ \App\Support\Decimal::trim($slip->overtime_hours) }} h @endif</div>
        <div class="col-6 col-md-4"><span class="text-secondary">PAN</span><br>{{ $e->pan ?: '—' }}</div>
        <div class="col-6 col-md-4"><span class="text-secondary">PF UAN</span><br>{{ $e->uan ?: '—' }}</div>
        <div class="col-6 col-md-4"><span class="text-secondary">Bank account</span><br>{{ $e->bank_account ? $e->bank_name.' '.$e->bank_account : '—' }}</div>
    </div>
    <div class="row g-3">
        @foreach (['earning' => ['Earnings', $slip->gross, 'Gross pay'], 'deduction' => ['Deductions', $slip->deductions, 'Total deductions']] as $type => [$title, $total, $totalLabel])
            <div class="col-12 col-md-6">
                <table class="table table-sm mb-0">
                    <thead class="table-light"><tr><th>{{ $title }}</th><th class="text-end">Amount</th></tr></thead>
                    <tbody>
                    @forelse ($slip->lines->where('type', $type) as $l)
                        <tr><td>{{ $l->name }}</td><td class="amount">{{ $m($l->amount) }}</td></tr>
                    @empty
                        <tr><td class="text-secondary" colspan="2">None</td></tr>
                    @endforelse
                    </tbody>
                    <tfoot><tr class="fw-semibold"><td>{{ $totalLabel }}</td><td class="amount">{{ $m($total) }}</td></tr></tfoot>
                </table>
            </div>
        @endforeach
    </div>
    <div class="d-flex justify-content-between align-items-center border-top mt-3 pt-3">
        <div class="fw-bold">Net pay</div>
        <div class="fs-5 fw-bold">₹ {{ $m($slip->net) }}</div>
    </div>
    <div class="small text-secondary">{{ \App\Support\NumberToWords::rupees(\App\Support\Money::toPaise($slip->net)) }}</div>
    @if ($slip->lines->where('type', 'employer')->isNotEmpty())
        <div class="small text-secondary mt-2">Employer contributions (not deducted from pay):
            {{ $slip->lines->where('type', 'employer')->map(fn ($l) => $l->name.' '.$m($l->amount))->implode(', ') }}.</div>
    @endif
    <div class="small text-secondary mt-3">This is a computer-generated payslip.</div>
</div>
@endsection
