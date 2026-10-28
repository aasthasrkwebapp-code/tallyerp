@extends('layouts.erp')

@section('title', 'Stock Journals')

@section('content')
<div class="page-header">
    <div>
        <h1>Stock journals</h1>
        <p class="text-secondary mb-0">Godown transfers and stock adjustments. They move quantity; their value shows through closing stock.</p>
    </div>
    @can('inventory.create')
        <div class="d-flex flex-wrap gap-2">
            <a href="{{ route('stock-journals.create', ['purpose' => 'transfer']) }}" class="btn btn-outline-primary">New transfer</a>
            <a href="{{ route('stock-journals.create', ['purpose' => 'adjustment']) }}" class="btn btn-primary" data-shortcut="c"><i class="bi bi-plus-lg me-1"></i>New adjustment</a>
        </div>
    @endcan
</div>

<form method="GET" class="erp-card p-3 mb-3">
    <div class="row g-2 align-items-end">
        <div class="col-12 col-md-4">
            <label class="form-label small" for="purpose">Type</label>
            <select id="purpose" name="purpose" class="form-select form-select-sm">
                <option value="">All</option>
                @foreach (\App\Models\StockJournal::PURPOSES as $k => $label)<option value="{{ $k }}" @selected(($filters['purpose'] ?? '') === $k)>{{ $label }}</option>@endforeach
            </select>
        </div>
        <div class="col-6 col-md-3"><label class="form-label small" for="from">From</label><input type="date" id="from" name="from" value="{{ $filters['from'] ?? '' }}" class="form-control form-control-sm"></div>
        <div class="col-6 col-md-3"><label class="form-label small" for="to">To</label><input type="date" id="to" name="to" value="{{ $filters['to'] ?? '' }}" class="form-control form-control-sm"></div>
        <div class="col-12 col-md-2"><button class="btn btn-sm btn-primary w-100">Filter</button></div>
    </div>
</form>

<div class="erp-card">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0 table-stack">
            <thead class="table-light"><tr><th>Date</th><th>Number</th><th>Type</th><th>Items</th><th>Status</th></tr></thead>
            <tbody>
            @forelse ($journals as $journal)
                <tr>
                    <td data-label="Date" class="text-nowrap">{{ $journal->date->format('d-m-Y') }}</td>
                    <td data-label="Number"><a href="{{ route('stock-journals.show', $journal) }}" class="fw-semibold">{{ $journal->number }}</a></td>
                    <td data-label="Type">{{ \App\Models\StockJournal::PURPOSES[$journal->purpose] ?? $journal->purpose }}@if ($journal->isDispatch()) <span class="badge text-bg-info">In transit</span>@elseif ($journal->dispatch_id) <span class="badge text-bg-light border">Transit receipt</span>@endif</td>
                    <td data-label="Items">{{ $journal->lines->pluck('item.name')->unique()->take(3)->implode(', ') }}@if ($journal->lines->pluck('stock_item_id')->unique()->count() > 3) …@endif</td>
                    <td data-label="Status">
                        @if ($journal->isPosted())<span class="badge text-bg-success">Posted</span>@else<span class="badge text-bg-secondary">Cancelled</span>@endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="5" class="text-secondary text-center py-4">No stock journals yet.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
<div class="mt-3">{{ $journals->links() }}</div>
@endsection
