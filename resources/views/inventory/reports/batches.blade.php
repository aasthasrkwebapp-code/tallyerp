@extends('layouts.erp')

@section('title', 'Batches & Expiry')

@php($q = fn ($milli, $unit) => \App\Services\Inventory\StockLedger::qty($milli, $unit))
@php($d = fn ($date) => $date ? \Illuminate\Support\Carbon::parse($date)->format('d-m-Y') : '—')
@php($names = $godowns->pluck('name', 'id'))

@section('content')
<div class="page-header">
    <div>
        <h1>Batches &amp; expiry</h1>
        <p class="text-secondary mb-0">Stock of items tracked by batch, as on {{ $d($asOf) }}.</p>
    </div>
    <a href="{{ route('stock-summary') }}" class="btn btn-outline-secondary">Stock summary</a>
</div>

<form method="GET" class="erp-card p-3 mb-3 no-print">
    <div class="row g-2 align-items-end">
        <div class="col-6 col-md-2">
            <label class="form-label small" for="b-to">As on</label>
            <input id="b-to" type="date" name="to" value="{{ $asOf }}" class="form-control form-control-sm">
        </div>
        <div class="col-6 col-md-3">
            <label class="form-label small" for="b-item">Item</label>
            <select id="b-item" name="item" class="form-select form-select-sm">
                <option value="">All batch items</option>
                @foreach ($items as $i)<option value="{{ $i->id }}" @selected((int) ($filters['item'] ?? 0) === $i->id)>{{ $i->name }}</option>@endforeach
            </select>
        </div>
        @if ($godowns->count() > 1)
            <div class="col-6 col-md-2">
                <label class="form-label small" for="b-godown">Godown</label>
                <select id="b-godown" name="godown" class="form-select form-select-sm">
                    <option value="">All</option>
                    @foreach ($godowns as $g)<option value="{{ $g->id }}" @selected((int) ($filters['godown'] ?? 0) === $g->id)>{{ $g->name }}</option>@endforeach
                </select>
            </div>
        @endif
        <div class="col-6 col-md-2">
            <label class="form-label small" for="b-status">Show</label>
            <select id="b-status" name="status" class="form-select form-select-sm">
                @foreach (['in_stock' => 'Batches in stock', 'expiring' => 'Expiring soon', 'expired' => 'Expired', 'all' => 'All, including empty'] as $k => $label)
                    <option value="{{ $k }}" @selected($status === $k)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-6 col-md-1">
            <label class="form-label small" for="b-days">Soon = days</label>
            <input id="b-days" type="number" min="1" max="3650" name="days" value="{{ $days }}" class="form-control form-control-sm">
        </div>
        <div class="col-12 col-md-auto d-flex flex-wrap gap-2">
            <button class="btn btn-sm btn-primary">Show</button>
            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="window.print()"><i class="bi bi-printer"></i> Print</button>
            @can('reports.export')
                <a class="btn btn-sm btn-outline-secondary" href="{{ request()->fullUrlWithQuery(['export' => 'csv']) }}"><i class="bi bi-filetype-csv"></i> CSV</a>
                <a class="btn btn-sm btn-outline-secondary" href="{{ request()->fullUrlWithQuery(['export' => 'xlsx']) }}"><i class="bi bi-file-earmark-excel"></i> Excel</a>
                <a class="btn btn-sm btn-outline-secondary" href="{{ request()->fullUrlWithQuery(['export' => 'pdf']) }}"><i class="bi bi-file-earmark-pdf"></i> PDF</a>
            @endcan
        </div>
    </div>
</form>

@if ($counts['expired'] || $counts['expiring'])
    <div class="alert alert-warning">
        @if ($counts['expired']){{ $counts['expired'] }} batch(es) in this list have expired. @endif
        @if ($counts['expiring']){{ $counts['expiring'] }} batch(es) expire within {{ $days }} days.@endif
    </div>
@endif

<div class="erp-card">
    <div class="table-responsive">
        <table class="table align-middle mb-0 table-stack">
            <thead class="table-light"><tr><th>Item</th><th>Batch</th>@if ($godowns->count() > 1)<th>Godown</th>@endif<th class="text-end">Quantity</th><th>Mfg date</th><th>Expiry</th><th>Status</th></tr></thead>
            <tbody>
            @forelse ($rows as $b)
                <tr>
                    <td data-label="Item"><a href="{{ route('stock-items.show', $b->item) }}">{{ $b->item->name }}</a></td>
                    <td data-label="Batch" class="fw-semibold">{{ $b->batch_no }}</td>
                    @if ($godowns->count() > 1)<td data-label="Godown">{{ $names[$b->godown_id] ?? '' }}</td>@endif
                    <td data-label="Quantity" class="text-end {{ $b->quantity < 0 ? 'text-danger' : '' }}">{{ $q($b->quantity, $b->item->unit) }}</td>
                    <td data-label="Mfg date">{{ $d($b->mfg_date) }}</td>
                    <td data-label="Expiry">{{ $d($b->expiry_date) }}</td>
                    <td data-label="Status">
                        @switch($b->state)
                            @case('expired')<span class="badge text-bg-danger">Expired {{ abs($b->days_left) }} day(s) ago</span>@break
                            @case('expiring')<span class="badge text-bg-warning">{{ $b->days_left === 0 ? 'Expires today' : 'Expires in '.$b->days_left.' day(s)' }}</span>@break
                            @case('ok')<span class="badge text-bg-success">{{ $b->days_left }} day(s) left</span>@break
                            @default<span class="text-secondary">No expiry</span>
                        @endswitch
                    </td>
                </tr>
            @empty
                <tr><td colspan="7" class="text-secondary text-center py-4">
                    @if ($items->isEmpty())No item is tracked by batch. Choose “Batch / lot numbers” on a stock item to start.@else No batches match.@endif
                </td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
