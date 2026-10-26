@extends('layouts.erp')

@php($M = \App\Support\Money::class)
@php($f = fn ($p) => $M::format($p, paiseInput: true))

@section('title', 'GSTR-1')

@section('content')
<div class="page-header no-print">
    <div>
        <h1>GSTR-1 <small class="text-secondary fs-6">Outward supplies</small></h1>
        <p class="text-secondary mb-0">{{ $totals['sales']['count'] }} invoices ({{ $M::format($totals['sales']['value'], true, true) }}) and {{ $totals['credit_notes']['count'] }} credit notes ({{ $M::format($totals['credit_notes']['value'], true, true) }}).</p>
    </div>
</div>

@include('gst._period')

@foreach ([
    'b2b' => ['4A — B2B invoices (registered recipients)', false],
    'b2cl' => ['5 — B2C large (inter-state to unregistered, invoice value above ₹1,00,000)', false],
    'exports' => ['6 — Exports and SEZ supplies', false],
    'cdnr' => ['9B — Credit notes to registered recipients', true],
    'cdnur' => ['9B — Credit notes to unregistered recipients', true],
] as $section => [$heading, $note])
    <div class="erp-card mb-3">
        <div class="erp-card-header d-flex justify-content-between align-items-center"><span>{{ $heading }} <span class="badge text-bg-light">{{ count($$section) }}</span></span>@include('gst._csv', ['section' => $section])</div>
        @include('gst._docs', ['docs' => $$section, 'note' => $note])
    </div>
@endforeach

<div class="erp-card mb-3">
    <div class="erp-card-header d-flex justify-content-between align-items-center"><span>7 — B2C small (by place of supply and rate)</span>@include('gst._csv', ['section' => 'b2cs'])</div>
    <div class="table-responsive">
        <table class="table table-sm mb-0 table-stack small">
            <thead class="table-light"><tr><th>Place of supply</th><th class="text-end">Rate</th><th class="text-end">Taxable</th><th class="text-end">IGST</th><th class="text-end">CGST</th><th class="text-end">SGST</th></tr></thead>
            <tbody>
            @forelse ($b2cs as $r)
                <tr><td data-label="Place of supply">{{ trim(($r['state_code'] ? $r['state_code'].'-' : '').$r['place_of_supply'], '-') }}</td><td data-label="Rate" class="text-end">{{ $r['rate'] }}%</td>
                    <td data-label="Taxable" class="amount">{{ $f($r['taxable']) }}</td><td data-label="IGST" class="amount">{{ $f($r['igst']) }}</td><td data-label="CGST" class="amount">{{ $f($r['cgst']) }}</td><td data-label="SGST" class="amount">{{ $f($r['sgst']) }}</td></tr>
            @empty
                <tr><td colspan="6" class="text-center text-secondary py-3">None in this period.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>

<div class="erp-card mb-3">
    <div class="erp-card-header d-flex justify-content-between align-items-center"><span>12 — HSN-wise summary (net of credit notes)</span>@include('gst._csv', ['section' => 'hsn'])</div>
    <div class="table-responsive">
        <table class="table table-sm mb-0 table-stack small">
            <thead class="table-light"><tr><th>HSN/SAC</th><th>Unit</th><th class="text-end">Quantity</th><th class="text-end">Rate</th><th class="text-end">Value</th><th class="text-end">Taxable</th><th class="text-end">IGST</th><th class="text-end">CGST</th><th class="text-end">SGST</th></tr></thead>
            <tbody>
            @forelse ($hsn as $r)
                <tr class="{{ $r['hsn'] ? '' : 'table-warning' }}">
                    <td data-label="HSN/SAC">{{ $r['hsn'] ?? 'Missing' }}</td><td data-label="Unit">{{ $r['unit'] ?? '—' }}</td>
                    <td data-label="Quantity" class="text-end">{{ \App\Support\Decimal::trim(\App\Support\Decimal::fromScaled($r['quantity'], 3)) }}</td><td data-label="Rate" class="text-end">{{ $r['rate'] }}%</td>
                    <td data-label="Value" class="amount">{{ $f($r['value']) }}</td><td data-label="Taxable" class="amount">{{ $f($r['taxable']) }}</td>
                    <td data-label="IGST" class="amount">{{ $f($r['igst']) }}</td><td data-label="CGST" class="amount">{{ $f($r['cgst']) }}</td><td data-label="SGST" class="amount">{{ $f($r['sgst']) }}</td>
                </tr>
            @empty
                <tr><td colspan="9" class="text-center text-secondary py-3">None in this period.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    @if (collect($hsn)->contains(fn ($r) => ! $r['hsn']))<div class="p-2 small text-warning-emphasis">Some items have no HSN/SAC code. Add it on the sales ledger or the invoice item.</div>@endif
</div>

<div class="erp-card mb-3">
    <div class="erp-card-header d-flex justify-content-between align-items-center"><span>13 — Documents issued</span>@include('gst._csv', ['section' => 'docs'])</div>
    <div class="table-responsive">
        <table class="table table-sm mb-0 table-stack small">
            <thead class="table-light"><tr><th>Document</th><th>From</th><th>To</th><th class="text-end">Total</th><th class="text-end">Cancelled</th><th class="text-end">Net issued</th></tr></thead>
            <tbody>
            @forelse ($documents as $r)
                <tr><td data-label="Document">{{ $r['type'] }}</td><td data-label="From">{{ $r['from'] }}</td><td data-label="To">{{ $r['to'] }}</td>
                    <td data-label="Total" class="text-end">{{ $r['total'] }}</td><td data-label="Cancelled" class="text-end">{{ $r['cancelled'] }}</td><td data-label="Net issued" class="text-end">{{ $r['total'] - $r['cancelled'] }}</td></tr>
            @empty
                <tr><td colspan="6" class="text-center text-secondary py-3">None in this period.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
