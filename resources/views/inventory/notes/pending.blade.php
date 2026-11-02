@extends('layouts.erp')

@php($m = fn ($p) => \App\Support\Money::format($p, paiseInput: true))
@php($q = fn ($milli, $r) => \App\Services\Inventory\StockLedger::qty($milli, $r->line->item->unit))
@php($title = $config['direction'] === 'out' ? 'Delivered, not invoiced' : 'Received, not billed')

@section('title', $title)

@section('content')
<div class="page-header">
    <div>
        <h1>{{ $title }}</h1>
        <p class="text-secondary mb-0">Lines of posted {{ mb_strtolower($config['plural']) }} without a posted {{ $config['invoice_kind'] === 'sales' ? 'sales invoice' : 'purchase bill' }}, oldest first. Value at the note rate, before tax.</p>
    </div>
    <a href="{{ route('notes.index', ['kind' => $kind]) }}" class="btn btn-outline-secondary">All {{ mb_strtolower($config['plural']) }}</a>
</div>

<form method="GET" class="erp-card p-3 mb-3 no-print">
    <input type="hidden" name="kind" value="{{ $kind }}">
    <div class="row g-2 align-items-end">
        <div class="col-12 col-md-4">
            <label class="form-label small" for="party">{{ $config['direction'] === 'out' ? 'Customer' : 'Supplier' }}</label>
            <select id="party" name="party" class="form-select form-select-sm">
                <option value="">All</option>
                @foreach ($parties as $p)<option value="{{ $p->id }}" @selected((int) ($filters['party'] ?? 0) === $p->id)>{{ $p->name }}</option>@endforeach
            </select>
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

<div class="erp-card">
    <div class="table-responsive">
        <table class="table align-middle mb-0 table-stack">
            <thead class="table-light"><tr><th>Note</th><th>Party</th><th>Item</th><th class="text-end">Quantity</th><th class="text-end">Invoiced</th><th class="text-end">Pending</th><th class="text-end">Value</th></tr></thead>
            <tbody>
            @forelse ($rows as $r)
                <tr>
                    <td data-label="Note"><a href="{{ route('notes.show', $r->note) }}" class="fw-semibold">{{ $r->note->number }}</a><div class="small text-secondary">{{ $r->note->date->format('d-m-Y') }}</div></td>
                    <td data-label="Party">{{ $r->note->party_name }}</td>
                    <td data-label="Item">{{ $r->line->description }}</td>
                    <td data-label="Quantity" class="text-end">{{ $q($r->quantity, $r) }}</td>
                    <td data-label="Invoiced" class="text-end">{{ $q($r->invoiced, $r) }}</td>
                    <td data-label="Pending" class="text-end fw-semibold">{{ $q($r->pending, $r) }}</td>
                    <td data-label="Value" class="amount">{{ $m($r->value) }}</td>
                </tr>
            @empty
                <tr><td colspan="7" class="text-secondary text-center py-4">Nothing pending.</td></tr>
            @endforelse
            </tbody>
            @if ($rows->isNotEmpty())
                <tfoot class="table-light fw-bold"><tr><td colspan="6" data-label="">Total</td><td class="amount" data-label="Total">{{ $m($rows->sum('value')) }}</td></tr></tfoot>
            @endif
        </table>
    </div>
</div>
@endsection
