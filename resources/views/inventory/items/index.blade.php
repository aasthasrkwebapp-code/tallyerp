@extends('layouts.erp')

@section('title', 'Stock Items')

@php($m = fn ($p) => \App\Support\Money::format($p, paiseInput: true))
@php($q = fn ($milli, $unit = null) => \App\Services\Inventory\StockLedger::qty($milli, $unit))

@section('content')
<div class="page-header">
    <div>
        <h1>Stock items</h1>
        <p class="text-secondary mb-0">Goods you buy, sell and hold. Quantities and values are as on today.</p>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <a href="{{ route('inventory-masters.index', 'units') }}" class="btn btn-outline-secondary">Units, groups &amp; godowns</a>
        @can('inventory.create')
            <a href="{{ route('stock-items.create') }}" class="btn btn-primary" data-shortcut="c"><i class="bi bi-plus-lg me-1"></i>New item</a>
        @endcan
    </div>
</div>

<form method="GET" class="erp-card p-3 mb-3">
    <div class="row g-2 align-items-end">
        <div class="col-12 col-md-3">
            <label class="form-label small" for="search">Search</label>
            <input id="search" name="search" value="{{ $filters['search'] ?? '' }}" class="form-control form-control-sm" placeholder="Name, alias or code">
        </div>
        <div class="col-6 col-md-2">
            <label class="form-label small" for="group">Group</label>
            <select id="group" name="group" class="form-select form-select-sm">
                <option value="">All groups</option>
                @foreach ($groups as $g)<option value="{{ $g->id }}" @selected((int) ($filters['group'] ?? 0) === $g->id)>{{ str_repeat('— ', $g->depth) }}{{ $g->name }}</option>@endforeach
            </select>
        </div>
        <div class="col-6 col-md-2">
            <label class="form-label small" for="category">Category</label>
            <select id="category" name="category" class="form-select form-select-sm">
                <option value="">All</option>
                @foreach ($categories as $c)<option value="{{ $c->id }}" @selected((int) ($filters['category'] ?? 0) === $c->id)>{{ $c->name }}</option>@endforeach
            </select>
        </div>
        <div class="col-6 col-md-2">
            <label class="form-label small" for="godown">Godown</label>
            <select id="godown" name="godown" class="form-select form-select-sm">
                <option value="">All godowns</option>
                @foreach ($godowns as $g)<option value="{{ $g->id }}" @selected((int) ($filters['godown'] ?? 0) === $g->id)>{{ $g->name }}</option>@endforeach
            </select>
        </div>
        <div class="col-6 col-md-2">
            <div class="form-check">
                <input class="form-check-input" type="checkbox" name="inactive" value="1" id="inactive" @checked($filters['inactive'] ?? false)>
                <label class="form-check-label small" for="inactive">Include inactive</label>
            </div>
        </div>
        <div class="col-12 col-md-1"><button class="btn btn-sm btn-primary w-100">Filter</button></div>
    </div>
</form>

<div class="erp-card">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0 table-stack">
            <thead class="table-light"><tr><th>Item</th><th>Group</th><th>HSN / GST</th><th class="text-end">In stock</th><th class="text-end">Rate</th><th class="text-end">Value</th><th class="text-end">Actions</th></tr></thead>
            <tbody>
            @forelse ($items as $item)
                @php($p = $positions[$item->id])
                @php($low = $item->reorder_level !== null && $p->quantity <= \App\Support\Decimal::toScaled($item->reorder_level, 3))
                <tr>
                    <td data-label="Item">
                        <a href="{{ route('stock-items.show', $item) }}" class="fw-semibold">{{ $item->name }}</a>
                        @if ($item->code)<span class="text-secondary small">· {{ $item->code }}</span>@endif
                        @unless ($item->is_active)<span class="badge text-bg-secondary">Inactive</span>@endunless
                    </td>
                    <td data-label="Group">{{ $item->group?->name ?? '—' }}</td>
                    <td data-label="HSN / GST">{{ $item->hsn_sac ?: '—' }}@if ($item->gst_rate !== null) · {{ \App\Support\Decimal::trim($item->gst_rate) }}%@endif</td>
                    <td data-label="In stock" class="text-end {{ $p->quantity < 0 ? 'text-danger' : ($low ? 'text-warning-emphasis' : '') }}">
                        {{ $q($p->quantity, $item->unit) }}
                        @if ($p->quantity < 0)<i class="bi bi-exclamation-triangle" title="Negative stock"></i>@elseif ($low)<i class="bi bi-arrow-down-circle" title="At or below reorder level"></i>@endif
                    </td>
                    <td data-label="Rate" class="amount">{{ $m($p->rate) }}</td>
                    <td data-label="Value" class="amount">{{ $m($p->value) }}</td>
                    <td data-label="" class="text-end text-nowrap">
                        @can('inventory.edit')<a href="{{ route('stock-items.edit', $item) }}" class="btn btn-sm btn-outline-secondary">Edit</a>@endcan
                    </td>
                </tr>
            @empty
                <tr><td colspan="7" class="text-secondary text-center py-4">No stock items yet.@can('inventory.create') <a href="{{ route('stock-items.create') }}">Create the first item</a>.@endcan</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
<div class="mt-3">{{ $items->links() }}</div>
@endsection
