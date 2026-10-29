@extends('layouts.erp')

@section('title', 'Serial Numbers')

@php($d = fn ($date) => $date && $date !== '0000-00-00' ? \Illuminate\Support\Carbon::parse($date)->format('d-m-Y') : 'Opening')
@php($docLink = fn ($r) => match ($r->doc_type) {
    'invoice' => route('invoices.show', $r->doc_id),
    'stock_journal' => route('stock-journals.show', $r->doc_id),
    'delivery_note' => route('notes.show', $r->doc_id),
    default => route('stock-items.show', $r->doc_id),
})

@section('content')
<div class="page-header">
    <div>
        <h1>Serial numbers</h1>
        <p class="text-secondary mb-0">Where each serial number is now. Search for a serial to see its history.</p>
    </div>
    <a href="{{ route('stock-summary') }}" class="btn btn-outline-secondary">Stock summary</a>
</div>

<form method="GET" class="erp-card p-3 mb-3 no-print">
    <div class="row g-2 align-items-end">
        <div class="col-12 col-md-3">
            <label class="form-label small" for="s-q">Serial number</label>
            <input id="s-q" name="q" value="{{ $filters['q'] ?? '' }}" class="form-control form-control-sm" maxlength="50" placeholder="Search">
        </div>
        <div class="col-6 col-md-3">
            <label class="form-label small" for="s-item">Item</label>
            <select id="s-item" name="item" class="form-select form-select-sm">
                <option value="">All serial items</option>
                @foreach ($items as $i)<option value="{{ $i->id }}" @selected((int) ($filters['item'] ?? 0) === $i->id)>{{ $i->name }}</option>@endforeach
            </select>
        </div>
        <div class="col-6 col-md-2">
            <label class="form-label small" for="s-status">Show</label>
            <select id="s-status" name="status" class="form-select form-select-sm">
                @foreach (['in_stock' => 'In stock', 'out' => 'Sold / issued', 'all' => 'All'] as $k => $label)
                    <option value="{{ $k }}" @selected($status === $k)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-12 col-md-auto d-flex flex-wrap gap-2">
            <button class="btn btn-sm btn-primary">Show</button>
            @can('reports.export')
                <a class="btn btn-sm btn-outline-secondary" href="{{ request()->fullUrlWithQuery(['export' => 'csv']) }}"><i class="bi bi-filetype-csv"></i> CSV</a>
                <a class="btn btn-sm btn-outline-secondary" href="{{ request()->fullUrlWithQuery(['export' => 'xlsx']) }}"><i class="bi bi-file-earmark-excel"></i> Excel</a>
                <a class="btn btn-sm btn-outline-secondary" href="{{ request()->fullUrlWithQuery(['export' => 'pdf']) }}"><i class="bi bi-file-earmark-pdf"></i> PDF</a>
            @endcan
        </div>
    </div>
</form>

@if ($history->isNotEmpty())
    <div class="erp-card mb-3">
        <div class="p-3 border-bottom fw-semibold">History of {{ $search }}</div>
        <div class="table-responsive">
            <table class="table align-middle mb-0 table-stack">
                <thead class="table-light"><tr><th>Date</th><th>Movement</th><th>Document</th><th>Party</th><th>Godown</th></tr></thead>
                <tbody>
                @foreach ($history as $h)
                    <tr>
                        <td data-label="Date">{{ $d($h->date) }}</td>
                        <td data-label="Movement">{!! $h->quantity > 0 ? '<span class="text-success">In</span>' : '<span class="text-danger">Out</span>' !!} · {{ \App\Services\Inventory\StockLedger::LABELS[$h->kind] ?? $h->kind }}</td>
                        <td data-label="Document"><a href="{{ $docLink($h) }}">{{ $h->number ?? 'Opening stock' }}</a></td>
                        <td data-label="Party">{{ $h->party ?? '' }}</td>
                        <td data-label="Godown">{{ $godowns[$h->godown_id] ?? '' }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </div>
@endif

<div class="erp-card">
    <div class="table-responsive">
        <table class="table align-middle mb-0 table-stack">
            <thead class="table-light"><tr><th>Serial</th><th>Item</th><th>Status</th><th>Last movement</th><th>Document</th></tr></thead>
            <tbody>
            @forelse ($rows as $r)
                <tr>
                    <td data-label="Serial" class="fw-semibold"><a href="{{ request()->fullUrlWithQuery(['q' => $r->serial, 'status' => 'all']) }}">{{ $r->serial }}</a></td>
                    <td data-label="Item"><a href="{{ route('stock-items.show', $r->item) }}">{{ $r->item->name }}</a></td>
                    <td data-label="Status">@if ($r->in_stock)<span class="badge text-bg-success">In stock</span> {{ $godowns->count() > 1 ? ($godowns[$r->godown_id] ?? '') : '' }}@else<span class="badge text-bg-secondary">Out</span>@endif</td>
                    <td data-label="Last movement">{{ \App\Services\Inventory\StockLedger::LABELS[$r->kind] ?? $r->kind }} · {{ $d($r->date) }}</td>
                    <td data-label="Document"><a href="{{ $docLink($r) }}">{{ $r->number ?? 'Opening stock' }}</a>@if ($r->party) · {{ $r->party }}@endif</td>
                </tr>
            @empty
                <tr><td colspan="5" class="text-secondary text-center py-4">
                    @if ($items->isEmpty())No item is tracked by serial number. Choose “Serial numbers” on a stock item to start.@else No serial numbers match.@endif
                </td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
