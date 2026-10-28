@php($m = fn (int $p) => \App\Support\Money::format($p, paiseInput: true))
@extends('pdf.layout', ['footerLeft' => 'Form 16 · FY '.$year->name.' · '.$employee->name])
@section('title', 'Form 16 '.$year->name.' '.$employee->name)

@section('content')
<div class="center" style="margin-bottom: 6pt">
    <h1>FORM No. 16</h1>
    <div class="small">[See rule 31(1)(a)]</div>
    <div class="bold" style="margin-top: 3pt">Certificate under section 203 of the Income-tax Act, 1961 for tax deducted at source on salary</div>
</div>

<div class="bold" style="margin: 6pt 0 3pt">PART A</div>
<table class="grid" style="margin-bottom: 6pt">
    <tr>
        <td style="width: 50%"><span class="muted small">Name and address of the employer</span><br><strong>{{ $company->legal_name ?: $company->displayName() }}</strong><br>{{ collect([$company->address, trim($company->city.' '.$company->pincode), $company->state])->filter()->implode(', ') }}</td>
        <td><span class="muted small">Name and designation of the employee</span><br><strong>{{ $employee->name }}</strong><br>{{ $employee->designation ?: '' }} ({{ $employee->code }})<br>{{ $employee->address }}</td>
    </tr>
    <tr><td><span class="muted small">PAN of the deductor</span> {{ $company->pan ?: '—' }} &nbsp; <span class="muted small">TAN of the deductor</span> {{ $company->tan ?: '—' }}</td>
        <td><span class="muted small">PAN of the employee</span> {{ $employee->pan ?: 'PANNOTAVBL' }}</td></tr>
    <tr><td><span class="muted small">Assessment year</span> {{ (int) $year->start_date->format('Y') + 1 }}-{{ substr((string) ((int) $year->start_date->format('Y') + 2), -2) }}</td>
        <td><span class="muted small">Period with the employer</span> {{ max($year->start_date, $employee->date_of_joining ?? $year->start_date)->format('d-m-Y') }} to {{ min($year->end_date, $employee->date_of_leaving ?? $year->end_date)->format('d-m-Y') }}</td></tr>
</table>
<table class="grid" style="margin-bottom: 8pt">
    <tr><th>Quarter</th><th>Receipt number of 24Q</th><th class="num">Amount paid / credited (₹)</th><th class="num">Tax deducted (₹)</th></tr>
    @foreach ($quarters as $q => $label)
        <tr><td>{{ $label }}</td><td>{{ $returns[$q]->receipt_number ?? '' }}</td><td class="num">{{ $m($salary['quarters'][$q]['paid']) }}</td><td class="num">{{ $m($salary['quarters'][$q]['tds']) }}</td></tr>
    @endforeach
    <tr class="total"><td colspan="2"><strong>Total</strong></td><td class="num"><strong>{{ $m(array_sum(array_column($salary['quarters'], 'paid'))) }}</strong></td><td class="num"><strong>{{ $m($tds_this_employer) }}</strong></td></tr>
</table>

<div class="bold" style="margin: 6pt 0 3pt">PART B (Annexure) · details of salary paid and tax deducted · {{ $regime === 'new' ? 'opted for section 115BAC (new regime)' : 'old regime' }}</div>
@include('payroll.form16.computation', ['tableClass' => 'lines', 'numClass' => 'num'])

<div style="margin-top: 10pt" class="small">
    <strong>Verification.</strong> I, ____________________, working in the capacity of ____________________, certify that the information given above is true, complete and correct, and is based on the books of account, documents, TDS statements and other available records.
</div>
<table style="margin-top: 18pt"><tr><td class="small">Place: {{ $company->city }}<br>Date: ____________</td><td class="right small">Signature of the person responsible for deduction of tax</td></tr></table>
@endsection
