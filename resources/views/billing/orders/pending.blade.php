@extends('layouts.erp')

@php($m = fn ($p) => \App\Support\Money::format($p, paiseInput: true))
@php($q = fn ($milli, $r) => \App\Services\Inventory\StockLedger::qty($milli).($r->item->unit ? ' '.$r->item->unit : ''))

@section('title', 'Pending '.$config['plural'])

@section('content')
<div class="page-header">
    <div>
        <h1>Pending {{ mb_strtolower($config['plural']) }}</h1>
        <p class="text-secondary mb-0">Lines of open orders not yet {{ $config['invoice_kind'] === 'sales' ? 'invoiced' : 'billed' }}, oldest first. Value is before tax.</p>
    </div>
    <a href="{{ route('orders.index', ['kind' => $kind]) }}" class="btn btn-outline-secondary">All {{ mb_strtolower($config['plural']) }}</a>
</div>

<form method="GET" class="erp-card p-3 mb-3 no-print">
    <input type="hidden" name="kind" value="{{ $kind }}">
    <div class="row g-2 align-items-end">
        <div class="col-12 col-md-4">
            <label class="form-label small" for="party">{{ $config['party_group'] === 'sundry_debtors' ? 'Customer' : 'Supplier' }}</label>
            <select id="party" name="party" class="form-select form-select-sm">
                <option value="">All</option>
                @foreach ($parties as $p)<option value="{{ $p->id }}" @selected((int) ($filters['party'] ?? 0) === $p->id)>{{ $p->name }}</option>@endforeach
            </select>
        </div>
        <div class="col-6 col-md-3">
            <div class="form-check mb-1">
                <input type="hidden" name="overdue" value="0">
                <input class="form-check-input" type="checkbox" name="overdue" value="1" id="overdue" @checked($filters['overdue'] ?? false)>
                <label class="form-check-label small" for="overdue">Only past delivery date</label>
            </div>
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
            <thead class="table-light"><tr><th>Order</th><th>Party</th><th>Item</th><th class="text-end">Ordered</th><th class="text-end">Invoiced</th><th class="text-end">Pending</th><th class="text-end">Value</th></tr></thead>
            <tbody>
            @forelse ($rows as $r)
                @php($late = $r->order->delivery_date && $r->order->delivery_date->toDateString() < $today)
                <tr>
                    <td data-label="Order"><a href="{{ route('orders.show', $r->order) }}" class="fw-semibold">{{ $r->order->number }}</a>
                        <div class="small text-secondary">{{ $r->order->date->format('d-m-Y') }}@if ($r->order->delivery_date) · by {{ $r->order->delivery_date->format('d-m-Y') }}@endif @if ($late)<span class="badge text-bg-danger">Overdue</span>@endif</div></td>
                    <td data-label="Party">{{ $r->order->party_name }}</td>
                    <td data-label="Item">{{ $r->item->description }}</td>
                    <td data-label="Ordered" class="text-end">{{ $q($r->ordered, $r) }}</td>
                    <td data-label="Invoiced" class="text-end">{{ $q($r->delivered, $r) }}</td>
                    <td data-label="Pending" class="text-end fw-semibold">{{ $q($r->pending, $r) }}</td>
                    <td data-label="Value" class="amount">{{ $m($r->pending_value) }}</td>
                </tr>
            @empty
                <tr><td colspan="7" class="text-secondary text-center py-4">Nothing pending.</td></tr>
            @endforelse
            </tbody>
            @if ($rows->isNotEmpty())
                <tfoot class="table-light fw-bold"><tr><td colspan="6" data-label="">Total pending</td><td class="amount" data-label="Total">{{ $m($rows->sum('pending_value')) }}</td></tr></tfoot>
            @endif
        </table>
    </div>
</div>
@endsection
