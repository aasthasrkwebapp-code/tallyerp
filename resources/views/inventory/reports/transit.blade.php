@extends('layouts.erp')

@section('title', 'Goods in Transit')

@php($p = fn ($paise) => \App\Support\Money::format($paise, paiseInput: true))
@php($q = fn ($milli, $unit) => \App\Services\Inventory\StockLedger::qty($milli, $unit))

@section('content')
<div class="page-header">
    <div>
        <h1>Goods in transit</h1>
        <p class="text-secondary mb-0">Stock dispatched between godowns and not yet received or written off on {{ \Illuminate\Support\Carbon::parse($date)->format('d M Y') }}</p>
    </div>
    <div class="d-flex flex-wrap gap-2 no-print">
        @can('inventory.create')<a href="{{ route('stock-journals.create', ['purpose' => 'transfer']) }}" class="btn btn-outline-secondary"><i class="bi bi-truck"></i> New transfer</a>@endcan
        <a href="{{ request()->fullUrlWithQuery(['export' => 'csv']) }}" class="btn btn-outline-secondary"><i class="bi bi-filetype-csv"></i> CSV</a>
        <a href="{{ request()->fullUrlWithQuery(['export' => 'xlsx']) }}" class="btn btn-outline-secondary"><i class="bi bi-file-earmark-excel"></i> Excel</a>
        <a href="{{ request()->fullUrlWithQuery(['export' => 'pdf']) }}" class="btn btn-outline-secondary"><i class="bi bi-file-earmark-pdf"></i> PDF</a>
    </div>
</div>

<form method="GET" class="erp-card p-3 mb-3 no-print">
    <div class="row g-2 align-items-end">
        <div class="col-8 col-sm-auto">
            <label class="form-label small" for="date">As on</label>
            <input id="date" type="date" name="date" value="{{ $date }}" class="form-control form-control-sm">
        </div>
        <div class="col-4 col-sm-auto"><button class="btn btn-sm btn-primary w-100">Show</button></div>
    </div>
</form>

<div class="erp-card">
    <div class="table-responsive">
        <table class="table table-sm align-middle mb-0 table-stack">
            <thead class="table-light"><tr><th>Dispatch</th><th>From → to</th><th class="text-end">Days</th><th>Item</th><th class="text-end">Dispatched</th><th class="text-end">In transit</th><th class="text-end">Value</th></tr></thead>
            <tbody>
            @forelse ($rows as $r)
                @foreach ($r->lines as $l)
                    <tr>
                        <td data-label="Dispatch">@if ($loop->first)<a href="{{ route('stock-journals.show', $r->dispatch) }}" class="fw-semibold">{{ $r->dispatch->number }}</a> <span class="small text-secondary">{{ $r->dispatch->date->format('d M Y') }}</span>@endif</td>
                        <td data-label="From → to">@if ($loop->first){{ $l->line->godown?->name }} → {{ $r->dispatch->transitTo?->name }}@endif</td>
                        <td data-label="Days" class="text-end">@if ($loop->first){{ (int) $r->dispatch->date->diffInDays(\Illuminate\Support\Carbon::parse($date)) }}@endif</td>
                        <td data-label="Item">{{ $l->line->item->name }}@include('inventory._tracking', ['line' => $l->line])</td>
                        <td data-label="Dispatched" class="text-end">{{ $q($l->dispatched, $l->line->item->unit) }}</td>
                        <td data-label="In transit" class="text-end fw-semibold">{{ $q($l->pending, $l->line->item->unit) }}</td>
                        <td data-label="Value" class="amount">{{ $p($l->value) }}</td>
                    </tr>
                @endforeach
            @empty
                <tr><td colspan="7" class="text-secondary text-center py-4">Nothing is in transit on this date.</td></tr>
            @endforelse
            </tbody>
            @if ($rows->isNotEmpty())
                <tfoot><tr class="row-total"><td colspan="6">Total in transit</td><td class="amount" data-label="Total">{{ $p($total) }}</td></tr></tfoot>
            @endif
        </table>
    </div>
</div>
<p class="small text-secondary mt-2">Goods in transit stay in closing stock, valued at the item's cost, in the transit godown until they are received. Open a dispatch to receive it or write off a shortage.</p>
@endsection
