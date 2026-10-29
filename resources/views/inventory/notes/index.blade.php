@extends('layouts.erp')

@section('title', $config['plural'])

@section('content')
<div class="page-header">
    <div>
        <h1>{{ $config['plural'] }}</h1>
        <p class="text-secondary mb-0">{{ $config['direction'] === 'out' ? 'Goods sent to customers before the sales invoice.' : 'Goods received from suppliers before the purchase bill.' }} Notes move stock; the {{ $config['invoice_kind'] === 'sales' ? 'invoice' : 'bill' }} made later does the accounting.</p>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <a href="{{ route('notes.pending', ['kind' => $kind]) }}" class="btn btn-outline-secondary"><i class="bi bi-hourglass me-1"></i>Not yet {{ $config['invoice_kind'] === 'sales' ? 'invoiced' : 'billed' }}</a>
        @can($config['module'].'.create')
            <a href="{{ route('notes.create', ['kind' => $kind]) }}" class="btn btn-primary" data-shortcut="c"><i class="bi bi-plus-lg me-1"></i>New {{ mb_strtolower($config['label']) }}</a>
        @endcan
    </div>
</div>

<form method="GET" class="erp-card p-3 mb-3">
    <input type="hidden" name="kind" value="{{ $kind }}">
    <div class="row g-2 align-items-end">
        <div class="col-12 col-md-5">
            <label class="form-label small" for="q">Search</label>
            <input id="q" name="q" value="{{ $filters['q'] ?? '' }}" class="form-control form-control-sm" placeholder="Number, party or reference">
        </div>
        <div class="col-6 col-md-2">
            <label class="form-label small" for="from">From</label>
            <input id="from" type="date" name="from" value="{{ $filters['from'] ?? '' }}" class="form-control form-control-sm">
        </div>
        <div class="col-6 col-md-2">
            <label class="form-label small" for="to">To</label>
            <input id="to" type="date" name="to" value="{{ $filters['to'] ?? '' }}" class="form-control form-control-sm">
        </div>
        <div class="col-12 col-md-3"><button class="btn btn-sm btn-primary w-100">Filter</button></div>
    </div>
</form>

<div class="erp-card">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0 table-stack">
            <thead class="table-light"><tr><th>Number</th><th>Date</th><th>Party</th><th>Reference</th><th>Status</th><th class="text-end">Lines</th></tr></thead>
            <tbody>
            @forelse ($notes as $n)
                <tr class="{{ $n->isPosted() ? '' : 'text-secondary text-decoration-line-through' }}">
                    <td data-label="Number"><a href="{{ route('notes.show', $n) }}" class="fw-semibold">{{ $n->number }}</a></td>
                    <td data-label="Date">{{ $n->date->format('d-m-Y') }}</td>
                    <td data-label="Party">{{ $n->party_name }}</td>
                    <td data-label="Reference">{{ $n->reference ?? '—' }}</td>
                    <td data-label="Status">@include('inventory.notes._status', ['label' => $labels[$n->id]])</td>
                    <td data-label="Lines" class="text-end">{{ $n->items->count() }}</td>
                </tr>
            @empty
                <tr><td colspan="6" class="text-center text-secondary py-4">No {{ mb_strtolower($config['plural']) }} yet.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
<div class="mt-3">{{ $notes->links() }}</div>
@endsection
