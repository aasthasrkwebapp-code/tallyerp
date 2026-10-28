@extends('layouts.erp')

@php($f = fn ($p) => \App\Support\Money::format($p, paiseInput: true))

@section('title', 'Purchase Register')

@section('content')
<div class="page-header no-print">
    <div>
        <h1>Purchase register <small class="text-secondary fs-6">Inward supplies</small></h1>
        <p class="text-secondary mb-0">Purchase bills and debit notes with supplier GSTIN, for matching with GSTR-2B.</p>
    </div>
    @include('gst._csv', ['section' => 'purchases'])
</div>

@include('gst._period')

<div class="erp-card">
    <div class="table-responsive">
        <table class="table table-sm align-middle mb-0 table-stack small">
            <thead class="table-light"><tr><th>Type</th><th>Number</th><th>Date</th><th>Supplier invoice</th><th>GSTIN</th><th>Supplier</th><th class="text-end">Value</th><th class="text-end">Taxable</th><th class="text-end">IGST</th><th class="text-end">CGST</th><th class="text-end">SGST</th><th class="text-end">Cess</th></tr></thead>
            <tbody>
            @forelse ($rows as $r)
                @php($s = $r['kind'] === 'debit_note' ? -1 : 1)
                <tr class="{{ $r['gstin'] ? '' : 'text-secondary' }}">
                    <td data-label="Type">{{ $r['kind'] === 'debit_note' ? 'Debit note' : 'Purchase' }}</td>
                    <td data-label="Number"><a href="{{ route('invoices.show', $r['invoice']) }}">{{ $r['number'] }}</a>@if ($r['reverse_charge']) <span class="badge text-bg-warning" title="Reverse charge: tax paid by you">RCM</span>@endif</td>
                    <td data-label="Date">{{ \Illuminate\Support\Carbon::parse($r['date'])->format('d-m-Y') }}</td>
                    <td data-label="Supplier invoice">{{ $r['supplier_invoice'] ?? '—' }}</td>
                    <td data-label="GSTIN" class="font-monospace">{{ $r['gstin'] ?? 'Unregistered' }}</td>
                    <td data-label="Supplier">{{ $r['party'] }}</td>
                    <td data-label="Value" class="amount">{{ $f($s * $r['value']) }}</td>
                    <td data-label="Taxable" class="amount">{{ $f($s * $r['taxable']) }}</td>
                    <td data-label="IGST" class="amount">{{ $f($s * $r['igst']) }}</td>
                    <td data-label="CGST" class="amount">{{ $f($s * $r['cgst']) }}</td>
                    <td data-label="SGST" class="amount">{{ $f($s * $r['sgst']) }}</td>
                    <td data-label="Cess" class="amount">{{ $f($s * $r['cess']) }}</td>
                </tr>
            @empty
                <tr><td colspan="12" class="text-center text-secondary py-4">No purchase bills in this period.</td></tr>
            @endforelse
            </tbody>
            @if ($rows->isNotEmpty())
                @php($sum = fn ($k) => $rows->sum(fn ($r) => ($r['kind'] === 'debit_note' ? -1 : 1) * $r[$k]))
                <tfoot class="table-light fw-bold">
                    <tr><td data-label="" colspan="6">Total</td><td data-label="Value" class="amount">{{ $f($sum('value')) }}</td><td data-label="Taxable" class="amount">{{ $f($sum('taxable')) }}</td><td data-label="IGST" class="amount">{{ $f($sum('igst')) }}</td><td data-label="CGST" class="amount">{{ $f($sum('cgst')) }}</td><td data-label="SGST" class="amount">{{ $f($sum('sgst')) }}</td><td data-label="Cess" class="amount">{{ $f($sum('cess')) }}</td></tr>
                </tfoot>
            @endif
        </table>
    </div>
</div>
@endsection
