@extends('layouts.erp')

@php($f = fn ($p) => \App\Support\Money::format($p, paiseInput: true))

@section('title', 'GSTR-3B')

@section('content')
<div class="page-header no-print">
    <div>
        <h1>GSTR-3B <small class="text-secondary fs-6">Summary return</small></h1>
        <p class="text-secondary mb-0">Outward supplies from sales invoices less credit notes; input tax credit from purchase bills less debit notes.</p>
    </div>
</div>

@include('gst._period')

<div class="erp-card mb-3">
    <div class="erp-card-header">3.1 — Outward supplies</div>
    <div class="table-responsive">
        <table class="table table-sm mb-0 table-stack">
            <thead class="table-light"><tr><th>Nature of supply</th><th class="text-end">Taxable value</th><th class="text-end">IGST</th><th class="text-end">CGST</th><th class="text-end">SGST/UTGST</th></tr></thead>
            <tbody>
            @foreach (['taxable' => '(a) Outward taxable supplies (other than zero rated, nil rated and exempted)', 'zero_rated' => '(b) Outward taxable supplies (zero rated: exports, SEZ)', 'nil_exempt' => '(c) Other outward supplies (nil rated, exempted)'] as $key => $label)
                @php($r = $outward[$key])
                <tr><td data-label="">{{ $label }}</td><td data-label="Taxable" class="amount">{{ $f($r['taxable']) }}</td><td data-label="IGST" class="amount">{{ $f($r['igst']) }}</td><td data-label="CGST" class="amount">{{ $f($r['cgst']) }}</td><td data-label="SGST" class="amount">{{ $f($r['sgst']) }}</td></tr>
            @endforeach
            </tbody>
        </table>
    </div>
</div>

<div class="erp-card mb-3">
    <div class="erp-card-header">4 — Eligible input tax credit</div>
    <div class="table-responsive">
        <table class="table table-sm mb-0 table-stack">
            <thead class="table-light"><tr><th>Details</th><th class="text-end">IGST</th><th class="text-end">CGST</th><th class="text-end">SGST/UTGST</th></tr></thead>
            <tbody>
                <tr><td data-label="">(A)(5) All other ITC (purchase bills)</td><td data-label="IGST" class="amount">{{ $f($itc['available']['igst']) }}</td><td data-label="CGST" class="amount">{{ $f($itc['available']['cgst']) }}</td><td data-label="SGST" class="amount">{{ $f($itc['available']['sgst']) }}</td></tr>
                <tr><td data-label="">(B) ITC reversed (debit notes / purchase returns)</td><td data-label="IGST" class="amount">{{ $f($itc['reversed']['igst']) }}</td><td data-label="CGST" class="amount">{{ $f($itc['reversed']['cgst']) }}</td><td data-label="SGST" class="amount">{{ $f($itc['reversed']['sgst']) }}</td></tr>
                <tr class="fw-bold"><td data-label="">(C) Net ITC available</td><td data-label="IGST" class="amount">{{ $f($itc['net']['igst']) }}</td><td data-label="CGST" class="amount">{{ $f($itc['net']['cgst']) }}</td><td data-label="SGST" class="amount">{{ $f($itc['net']['sgst']) }}</td></tr>
            </tbody>
        </table>
    </div>
</div>

<div class="erp-card mb-3">
    <div class="erp-card-header">6.1 — Payment of tax (indicative set-off)</div>
    <div class="table-responsive">
        <table class="table table-sm mb-0 table-stack">
            <thead class="table-light"><tr><th>Tax</th><th class="text-end">Liability</th><th class="text-end">Paid by IGST credit</th><th class="text-end">Paid by CGST credit</th><th class="text-end">Paid by SGST credit</th><th class="text-end">Payable in cash</th></tr></thead>
            <tbody>
            @foreach (['igst' => 'Integrated tax', 'cgst' => 'Central tax', 'sgst' => 'State / UT tax'] as $head => $label)
                <tr>
                    <td data-label="Tax">{{ $label }}</td>
                    <td data-label="Liability" class="amount">{{ $f(max(0, $liability[$head])) }}</td>
                    @foreach (['igst', 'cgst', 'sgst'] as $credit)<td data-label="By {{ strtoupper($credit) }}" class="amount">{{ $f($setoff['used'][$credit][$head] ?? 0) }}</td>@endforeach
                    <td data-label="Cash" class="amount fw-bold">{{ $f($setoff['cash'][$head]) }}</td>
                </tr>
            @endforeach
            </tbody>
            <tfoot class="table-light">
                <tr><td data-label="">Total payable in cash</td><td data-label="" colspan="4"></td><td data-label="Total" class="amount fw-bold">{{ $f(array_sum($setoff['cash'])) }}</td></tr>
                <tr><td data-label="">Unused credit carried forward</td><td data-label=""></td><td data-label="IGST" class="amount">{{ $f($setoff['carried_forward']['igst']) }}</td><td data-label="CGST" class="amount">{{ $f($setoff['carried_forward']['cgst']) }}</td><td data-label="SGST" class="amount">{{ $f($setoff['carried_forward']['sgst']) }}</td><td data-label=""></td></tr>
            </tfoot>
        </table>
    </div>
    <p class="small text-secondary p-2 mb-0">IGST credit is used first (IGST, then CGST, then SGST); CGST credit against CGST then IGST; SGST credit against SGST then IGST. Credit brought forward from earlier months, interest and late fee are not included.</p>
</div>
@endsection
