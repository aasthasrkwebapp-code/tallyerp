@extends('layouts.erp')

@section('title', 'Reorder Status')

@php($m = fn ($p) => \App\Support\Money::format($p, paiseInput: true))
@php($q = fn ($milli, $unit) => \App\Services\Inventory\StockLedger::qty($milli, $unit))

@section('content')
<div class="page-header">
    <div>
        <h1>Reorder status</h1>
        <p class="text-secondary mb-0">Active items at or below their reorder level, or with negative stock, as on today.</p>
    </div>
    <a href="{{ route('stock-summary') }}" class="btn btn-outline-secondary">Stock summary</a>
</div>

<div class="erp-card">
    <div class="table-responsive">
        <table class="table align-middle mb-0 table-stack">
            <thead class="table-light"><tr><th>Item</th><th class="text-end">In stock</th><th class="text-end">Reorder level</th><th class="text-end">Shortfall</th><th class="text-end">Last purchase rate</th><th></th></tr></thead>
            <tbody>
            @forelse ($rows as $p)
                <tr>
                    <td data-label="Item"><a href="{{ route('stock-items.show', $p->item) }}" class="fw-semibold">{{ $p->item->name }}</a> <span class="text-secondary small">{{ $p->item->group?->name }}</span></td>
                    <td data-label="In stock" class="text-end {{ $p->quantity < 0 ? 'text-danger' : '' }}">{{ $q($p->quantity, $p->item->unit) }}</td>
                    <td data-label="Reorder level" class="text-end">{{ $p->item->reorder_level !== null ? $q($p->level, $p->item->unit) : '—' }}</td>
                    <td data-label="Shortfall" class="text-end fw-semibold">{{ $q($p->shortfall, $p->item->unit) }}</td>
                    <td data-label="Purchase rate" class="amount">{{ $p->item->purchase_rate !== null ? $m(\App\Support\Money::toPaise($p->item->purchase_rate)) : '—' }}</td>
                    <td data-label="" class="text-end">
                        @can('purchases.create')<a href="{{ route('invoices.create', ['kind' => 'purchase']) }}" class="btn btn-sm btn-outline-primary">Purchase bill</a>@endcan
                    </td>
                </tr>
            @empty
                <tr><td colspan="6" class="text-secondary text-center py-4">All items are above their reorder levels.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
