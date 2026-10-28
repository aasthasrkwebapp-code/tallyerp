@extends('layouts.erp')

@php($f = fn ($p) => \App\Support\Money::format($p, paiseInput: true))

@section('title', 'GSTR-3B')

@section('content')
<div class="page-header no-print">
    <div>
        <h1>GSTR-3B <small class="text-secondary fs-6">Summary return</small></h1>
        <p class="text-secondary mb-0">Outward supplies from sales invoices less credit notes; input tax credit from purchase bills less debit notes.</p>
    </div>
    @can('reports.export')
        <a href="{{ route('gst.gstr3b-json', ['from' => $from, 'to' => $to]) }}" class="btn btn-outline-primary" title="JSON file for the GST portal's GSTR-3B offline utility or upload"><i class="bi bi-filetype-json"></i> JSON for the GST portal</a>
    @endcan
</div>

@include('gst._period')

<div class="erp-card mb-3">
    <div class="erp-card-header">3.1 — Outward supplies and inward supplies liable to reverse charge</div>
    <div class="table-responsive">
        <table class="table table-sm mb-0 table-stack">
            <thead class="table-light"><tr><th>Nature of supply</th><th class="text-end">Taxable value</th><th class="text-end">IGST</th><th class="text-end">CGST</th><th class="text-end">SGST/UTGST</th><th class="text-end">Cess</th></tr></thead>
            <tbody>
            @foreach ([
                '(a) Outward taxable supplies (other than zero rated, nil rated and exempted)' => $outward['taxable'],
                '(b) Outward taxable supplies (zero rated: exports, SEZ)' => $outward['zero_rated'],
                '(c) Other outward supplies (nil rated, exempted)' => $outward['nil_exempt'],
                '(d) Inward supplies liable to reverse charge' => $inward_rcm,
            ] as $label => $r)
                <tr><td data-label="">{{ $label }}</td><td data-label="Taxable" class="amount">{{ $f($r['taxable']) }}</td><td data-label="IGST" class="amount">{{ $f($r['igst']) }}</td><td data-label="CGST" class="amount">{{ $f($r['cgst']) }}</td><td data-label="SGST" class="amount">{{ $f($r['sgst']) }}</td><td data-label="Cess" class="amount">{{ $f($r['cess']) }}</td></tr>
            @endforeach
            </tbody>
        </table>
    </div>
    @if ($outward['rcm_outward']['taxable'])
        <p class="small text-secondary p-2 mb-0">Sales on which the customer pays tax under reverse charge: taxable value {{ $f($outward['rcm_outward']['taxable']) }}. No tax is due from you on these; they are reported in GSTR-1 with the reverse charge flag.</p>
    @endif
</div>

<div class="erp-card mb-3">
    <div class="erp-card-header">4 — Eligible input tax credit</div>
    <div class="table-responsive">
        <table class="table table-sm mb-0 table-stack">
            <thead class="table-light"><tr><th>Details</th><th class="text-end">IGST</th><th class="text-end">CGST</th><th class="text-end">SGST/UTGST</th><th class="text-end">Cess</th></tr></thead>
            <tbody>
            @foreach ([
                ['(A)(3) Inward supplies liable to reverse charge', $itc['rcm'], ''],
                ['(A)(5) All other ITC (purchase bills)', $itc['other'], ''],
                ['(B) ITC reversed (debit notes / purchase returns)', $itc['reversed'], ''],
                ['(C) Net ITC available', $itc['net'], 'fw-bold'],
            ] as [$label, $r, $class])
                <tr class="{{ $class }}"><td data-label="">{{ $label }}</td>@foreach (['igst' => 'IGST', 'cgst' => 'CGST', 'sgst' => 'SGST', 'cess' => 'Cess'] as $k => $h)<td data-label="{{ $h }}" class="amount">{{ $f($r[$k]) }}</td>@endforeach</tr>
            @endforeach
            </tbody>
        </table>
    </div>
</div>

<div class="erp-card mb-3">
    <div class="erp-card-header">6.1 — Payment of tax (indicative set-off)</div>
    <div class="table-responsive">
        <table class="table table-sm mb-0 table-stack">
            <thead class="table-light"><tr><th>Tax</th><th class="text-end">Liability</th><th class="text-end">Paid by IGST credit</th><th class="text-end">Paid by CGST credit</th><th class="text-end">Paid by SGST credit</th><th class="text-end">Paid by cess credit</th><th class="text-end">Payable in cash</th><th class="text-end">Reverse charge (cash)</th></tr></thead>
            <tbody>
            @foreach (['igst' => 'Integrated tax', 'cgst' => 'Central tax', 'sgst' => 'State / UT tax', 'cess' => 'Cess'] as $head => $label)
                <tr>
                    <td data-label="Tax">{{ $label }}</td>
                    <td data-label="Liability" class="amount">{{ $f(max(0, $liability[$head])) }}</td>
                    @foreach (['igst', 'cgst', 'sgst', 'cess'] as $credit)<td data-label="By {{ strtoupper($credit) }}" class="amount">{{ $f($setoff['used'][$credit][$head] ?? 0) }}</td>@endforeach
                    <td data-label="Cash" class="amount fw-bold">{{ $f($setoff['cash'][$head]) }}</td>
                    <td data-label="Reverse charge" class="amount fw-bold">{{ $f(max(0, $rcm_liability[$head])) }}</td>
                </tr>
            @endforeach
            </tbody>
            <tfoot class="table-light">
                <tr><td data-label="">Total payable in cash</td><td data-label="" colspan="6"></td><td data-label="Total" class="amount fw-bold">{{ $f($cash_total) }}</td></tr>
                <tr><td data-label="">Unused credit carried forward</td><td data-label=""></td>@foreach (['igst' => 'IGST', 'cgst' => 'CGST', 'sgst' => 'SGST', 'cess' => 'Cess'] as $k => $h)<td data-label="{{ $h }}" class="amount">{{ $f($setoff['carried_forward'][$k]) }}</td>@endforeach<td data-label="" colspan="2"></td></tr>
            </tfoot>
        </table>
    </div>
    <p class="small text-secondary p-2 mb-0">IGST credit is used first (IGST, then CGST, then SGST); CGST credit against CGST then IGST; SGST credit against SGST then IGST; cess credit only against cess. Tax under reverse charge must be paid in cash; its credit then appears under 4(A)(3). Credit brought forward from earlier months, interest and late fee are not included.</p>
</div>
@endsection
