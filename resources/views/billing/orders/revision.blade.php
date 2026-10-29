@extends('layouts.erp')

@php($d = $saved->data)
@php($version = $saved->revision ? 'Rev '.$saved->revision : 'Original')

@section('title', $config['label'].' '.$order->number.' '.$version)

@section('content')
<div class="page-header">
    <div>
        <h1>{{ $config['label'] }} {{ $order->number }} <span class="badge text-bg-secondary align-middle">{{ $version }}</span></h1>
        <p class="text-secondary mb-0">As it was until {{ $saved->created_at->format('d M Y H:i') }}@if ($saved->reviser), when {{ $saved->reviser->name }} revised it @endif @if ($saved->note) · {{ $saved->note }}@endif</p>
    </div>
    <a href="{{ route('orders.show', $order) }}" class="btn btn-outline-secondary">Current version (Rev {{ $order->revision }})</a>
</div>

<div class="erp-card p-3 mb-3">
    <div class="fw-semibold mb-2">What {{ $nextLabel }} changed</div>
    @if ($changes)
        <ul class="mb-0 small">@foreach ($changes as $c)<li>{{ $c }}</li>@endforeach</ul>
    @else
        <p class="mb-0 small text-secondary">No differences in the fields compared.</p>
    @endif
</div>

<div class="erp-card p-3 mb-3 small">
    <div class="row g-2">
        <div class="col-6 col-md-3"><div class="text-secondary">Party</div>{{ $d['party_name'] ?? '—' }}</div>
        <div class="col-6 col-md-3"><div class="text-secondary">Date</div>{{ $d['input']['date'] ?? '—' }}</div>
        <div class="col-6 col-md-3"><div class="text-secondary">{{ $config['date_label'] ?? 'Delivery by' }}</div>{{ $d['input']['delivery_date'] ?? '—' }}</div>
        <div class="col-6 col-md-3"><div class="text-secondary">Reference</div>{{ $d['input']['reference'] ?? '—' }}</div>
    </div>
</div>

<div class="erp-card mb-3">
    <div class="table-responsive">
        <table class="table align-middle mb-0 table-stack">
            <thead class="table-light"><tr><th>#</th><th>Description</th><th class="text-end">Quantity</th><th class="text-end">Rate</th><th class="text-end">Disc %</th><th class="text-end">GST %</th><th class="text-end">Amount</th></tr></thead>
            <tbody>
            @foreach ($d['lines'] ?? [] as $i => $l)
                <tr>
                    <td data-label="#">{{ $i + 1 }}</td>
                    <td data-label="Description">{{ $l['description'] }}</td>
                    <td data-label="Quantity" class="text-end">{{ $l['quantity'] }} {{ $l['unit'] }}</td>
                    <td data-label="Rate" class="amount">{{ \App\Support\Money::format($l['rate']) }}</td>
                    <td data-label="Disc %" class="text-end">{{ $l['discount_percent'] }}</td>
                    <td data-label="GST %" class="text-end">{{ $l['gst_rate'] }}</td>
                    <td data-label="Amount" class="amount">{{ \App\Support\Money::format($l['total_amount']) }}</td>
                </tr>
            @endforeach
            </tbody>
            <tfoot>
                <tr class="row-total"><td colspan="6">Total then</td><td class="amount" data-label="Total then">{{ \App\Support\Money::format($d['totals']['grand_total'] ?? 0, true) }}</td></tr>
                <tr><td colspan="6" class="text-secondary">Total now (Rev {{ $order->revision }})</td><td class="amount text-secondary" data-label="Total now">{{ \App\Support\Money::format($current['totals']['grand_total'], true) }}</td></tr>
            </tfoot>
        </table>
    </div>
</div>
@if (! empty($d['input']['terms']))<div class="erp-card p-3 mb-3 small"><div class="text-secondary">Terms</div><span style="white-space: pre-line">{{ $d['input']['terms'] }}</span></div>@endif
@endsection
