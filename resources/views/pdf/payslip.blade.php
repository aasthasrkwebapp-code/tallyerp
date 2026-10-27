@php($m = fn ($v) => \App\Support\Money::format($v))
@php($e = $slip->employee)
@extends('pdf.layout', ['footerLeft' => 'Payslip '.$run->label().' · '.$slip->employee_name])
@section('title', 'Payslip '.$run->label().' '.$slip->employee_name)

@section('content')
<div class="center" style="margin-bottom: 8pt">
    <h1>{{ $company->displayName() }}</h1>
    <div class="muted small">{{ $company->address }}</div>
    <div class="bold" style="margin-top: 4pt">Payslip for {{ $run->label() }} @if ($run->isDraft())<span class="stamp">DRAFT</span>@endif</div>
</div>
<table class="grid" style="margin-bottom: 8pt">
    <tr><td><span class="muted small">Employee</span><br>{{ $slip->employee_name }} ({{ $slip->employee_code }})</td><td><span class="muted small">Designation</span><br>{{ $slip->designation ?: '—' }}</td><td><span class="muted small">Paid days</span><br>{{ \App\Support\Decimal::trim($slip->paid_days) }} of {{ $slip->days_in_month }}</td></tr>
    <tr><td><span class="muted small">PAN</span><br>{{ $e->pan ?: '—' }}</td><td><span class="muted small">PF UAN</span><br>{{ $e->uan ?: '—' }}</td><td><span class="muted small">Bank account</span><br>{{ $e->bank_account ? $e->bank_name.' '.$e->bank_account : '—' }}</td></tr>
</table>
<table>
    <tr>
        @foreach (['earning' => ['Earnings', $slip->gross, 'Gross pay'], 'deduction' => ['Deductions', $slip->deductions, 'Total deductions']] as $type => [$title, $total, $totalLabel])
            <td style="width: 50%; vertical-align: top; {{ $loop->first ? 'padding-right: 6pt' : 'padding-left: 6pt' }}">
                <table class="lines">
                    <thead><tr><th>{{ $title }}</th><th class="num">Amount</th></tr></thead>
                    <tbody>
                    @forelse ($slip->lines->where('type', $type) as $l)
                        <tr><td>{{ $l->name }}</td><td class="num">{{ $m($l->amount) }}</td></tr>
                    @empty
                        <tr><td class="muted" colspan="2">None</td></tr>
                    @endforelse
                    <tr class="total"><td>{{ $totalLabel }}</td><td class="num">{{ $m($total) }}</td></tr>
                    </tbody>
                </table>
            </td>
        @endforeach
    </tr>
</table>
<table style="margin-top: 10pt; border-top: 1pt solid #333">
    <tr><td class="bold" style="padding-top: 5pt">Net pay</td><td class="right bold" style="font-size: 12pt; padding-top: 5pt">₹ {{ $m($slip->net) }}</td></tr>
</table>
<div class="muted small">{{ \App\Support\NumberToWords::rupees(\App\Support\Money::toPaise($slip->net)) }}</div>
@if ($slip->lines->where('type', 'employer')->isNotEmpty())
    <div class="muted small" style="margin-top: 5pt">Employer contributions (not deducted from pay): {{ $slip->lines->where('type', 'employer')->map(fn ($l) => $l->name.' '.$m($l->amount))->implode(', ') }}.</div>
@endif
<div class="muted small" style="margin-top: 10pt">This is a computer-generated payslip.</div>
@endsection
