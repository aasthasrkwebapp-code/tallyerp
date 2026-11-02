@extends('layouts.erp')

@section('title', 'Stock Ageing')

@php($m = fn ($p) => \App\Support\Money::format($p, paiseInput: true))
@php($q = fn ($milli, $unit) => \App\Services\Inventory\StockLedger::qty($milli, $unit))

@section('content')
<div class="page-header">
    <div>
        <h1>Stock ageing</h1>
        <p class="text-secondary mb-0">Stock in hand as on {{ \Illuminate\Support\Carbon::parse($asOf)->format('d M Y') }}@if ($godownId) · {{ $godowns->firstWhere('id', $godownId)?->name }}@endif, by how long ago it was received</p>
    </div>
    <a href="{{ route('stock-summary', ['to' => $asOf, 'godown' => $godownId]) }}" class="btn btn-outline-secondary">Stock summary</a>
</div>

@component('reports._period', ['single' => true, 'csv' => true, 'from' => $asOf, 'to' => $asOf])
    <div class="col-6 col-md-2">
        <label class="form-label small" for="godown">Godown</label>
        <select id="godown" name="godown" class="form-select form-select-sm">
            <option value="">All godowns</option>
            @foreach ($godowns as $g)<option value="{{ $g->id }}" @selected($godownId === $g->id)>{{ $g->name }}</option>@endforeach
        </select>
    </div>
    <div class="col-6 col-md-2">
        <label class="form-label small" for="group">Stock group</label>
        <select id="group" name="group" class="form-select form-select-sm">
            <option value="">All groups</option>
            @foreach ($groups as $g)<option value="{{ $g->id }}" @selected((int) ($filters['group'] ?? 0) === $g->id)>{{ str_repeat('— ', $g->depth) }}{{ $g->name }}</option>@endforeach
        </select>
    </div>
    <div class="col-12 col-md-2">
        <label class="form-label small" for="slabs">Slabs (days)</label>
        <input id="slabs" name="slabs" value="{{ old('slabs', $slabText) }}" class="form-control form-control-sm @error('slabs') is-invalid @enderror" placeholder="30,60,90,180">
        @error('slabs')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
@endcomponent

<div class="erp-card">
    <div class="table-responsive">
        <table class="table table-sm table-report align-middle mb-0 table-stack">
            <thead class="table-light">
                <tr>
                    <th>Item</th>
                    <th class="text-end">In hand</th>
                    @foreach ($labels as $label)<th class="text-end">{{ $label }}</th>@endforeach
                    <th class="text-end">Oldest receipt</th>
                </tr>
            </thead>
            <tbody>
            @forelse ($rows as $r)
                <tr>
                    <td data-label="Item"><a href="{{ route('stock-items.show', [$r->item, 'to' => $asOf, 'godown' => $godownId]) }}" class="fw-semibold">{{ $r->item->name }}</a> <span class="text-secondary small">{{ $r->item->group?->name }}</span></td>
                    <td data-label="In hand" class="text-end">{{ $q($r->quantity, $r->item->unit) }}<div class="small text-secondary">{{ $m($r->value) }}</div></td>
                    @foreach ($r->slabs as $s => [$qty, $value])
                        <td data-label="{{ $labels[$s] }}" class="text-end {{ $qty && $s === count($labels) - 1 ? 'text-danger' : '' }}">
                            @if ($qty){{ $q($qty, $r->item->unit) }}<div class="small text-secondary">{{ $m($value) }}</div>@else<span class="text-secondary">—</span>@endif
                        </td>
                    @endforeach
                    <td data-label="Oldest receipt" class="text-end">{{ $r->oldest ? \Illuminate\Support\Carbon::parse($r->oldest)->format('d M Y') : '—' }}@if ($r->days !== null)<div class="small text-secondary">{{ $r->days }} days</div>@endif</td>
                </tr>
            @empty
                <tr><td colspan="{{ count($labels) + 3 }}" class="text-secondary text-center py-4">No stock in hand on this date.</td></tr>
            @endforelse
            </tbody>
            @if ($rows->isNotEmpty())
                <tfoot>
                    <tr class="row-total">
                        <td>Total value</td>
                        <td class="amount" data-label="In hand">{{ $m($total_value) }}</td>
                        @foreach ($totals as $s => $value)<td class="amount" data-label="{{ $labels[$s] }}">{{ $m($value) }}</td>@endforeach
                        <td></td>
                    </tr>
                </tfoot>
            @endif
        </table>
    </div>
</div>
<p class="small text-secondary mt-2">Stock is taken to go out oldest first, so what is in hand is the latest receipts. Opening stock is dated on the books beginning date. Values are each slab's share of the item's closing value, so the total matches the stock summary.</p>
@endsection
