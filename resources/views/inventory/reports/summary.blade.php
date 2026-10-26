@extends('layouts.erp')

@section('title', 'Stock Summary')

@php($m = fn ($p) => \App\Support\Money::format($p, paiseInput: true))
@php($q = fn ($milli, $unit) => \App\Services\Inventory\StockLedger::qty($milli, $unit))
@php($to = $asOf)

@section('content')
<div class="page-header">
    <div>
        <h1>Stock summary</h1>
        <p class="text-secondary mb-0">As on {{ \Illuminate\Support\Carbon::parse($asOf)->format('d M Y') }}@if ($godownId) · {{ $godowns->firstWhere('id', $godownId)?->name }}@endif · weighted average cost</p>
    </div>
    <a href="{{ route('stock-reorder') }}" class="btn btn-outline-secondary">Reorder status</a>
</div>

@component('reports._period', ['single' => true, 'csv' => true, 'from' => $asOf, 'to' => $asOf])
    <div class="col-6 col-md-3">
        <label class="form-label small" for="godown">Godown</label>
        <select id="godown" name="godown" class="form-select form-select-sm">
            <option value="">All godowns</option>
            @foreach ($godowns as $g)<option value="{{ $g->id }}" @selected($godownId === $g->id)>{{ $g->name }}</option>@endforeach
        </select>
    </div>
    <div class="col-6 col-md-2">
        <div class="form-check">
            <input class="form-check-input" type="checkbox" name="zero" value="1" id="zero" @checked($filters['zero'] ?? false)>
            <label class="form-check-label small" for="zero">Show nil items</label>
        </div>
    </div>
@endcomponent

<div class="erp-card">
    <div class="table-responsive">
        <table class="table table-sm table-report align-middle mb-0 table-stack">
            <thead class="table-light"><tr><th>Item</th><th class="text-end">Quantity</th><th class="text-end">Rate</th><th class="text-end">Value</th></tr></thead>
            <tbody>
            @forelse ($sections as $section)
                <tr class="row-group">
                    <td colspan="3" class="indent-{{ min($section->group?->depth ?? 0, 3) }}">{{ $section->group?->name ?? 'Not grouped' }}</td>
                    <td class="amount" data-label="Group value">{{ $m($section->rows->sum('value')) }}</td>
                </tr>
                @foreach ($section->rows as $p)
                    <tr>
                        <td data-label="Item" class="indent-{{ min(($section->group?->depth ?? 0) + 1, 3) }}"><a href="{{ route('stock-items.show', [$p->item, 'to' => $asOf, 'godown' => $godownId]) }}">{{ $p->item->name }}</a></td>
                        <td data-label="Quantity" class="text-end {{ $p->quantity < 0 ? 'text-danger' : '' }}">{{ $q($p->quantity, $p->item->unit) }}</td>
                        <td data-label="Rate" class="amount">{{ $m($p->rate) }}</td>
                        <td data-label="Value" class="amount">{{ $m($p->value) }}</td>
                    </tr>
                @endforeach
            @empty
                <tr><td colspan="4" class="text-secondary text-center py-4">No stock on this date.</td></tr>
            @endforelse
            </tbody>
            <tfoot><tr class="row-total"><td colspan="3">Total stock value</td><td class="amount">{{ $m($total) }}</td></tr></tfoot>
        </table>
    </div>
</div>
<p class="small text-secondary mt-2">The average cost uses opening stock, purchases (less purchase returns) and valued stock-journal inwards up to the date, across all godowns. A godown filter limits quantities only.</p>
@endsection
