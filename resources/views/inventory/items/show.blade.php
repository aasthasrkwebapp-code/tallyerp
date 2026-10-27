@extends('layouts.erp')

@section('title', $item->name)

@php($m = fn ($p) => \App\Support\Money::format($p, paiseInput: true))
@php($q = fn ($milli) => \App\Services\Inventory\StockLedger::qty($milli, $item->unit))
@php($labels = \App\Services\Inventory\StockLedger::LABELS)

@section('content')
<div class="page-header">
    <div>
        <h1>{{ $item->name }} @unless ($item->is_active)<span class="badge text-bg-secondary align-middle">Inactive</span>@endunless</h1>
        <p class="text-secondary mb-0">
            {{ $item->group?->name ?? 'Primary' }}@if ($item->category) · {{ $item->category->name }}@endif
            @if ($item->code) · Code {{ $item->code }}@endif
            · Unit {{ $item->unit->symbol }}
        </p>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <a href="{{ route('stock-items.index') }}" class="btn btn-outline-secondary">All items</a>
        @can('inventory.edit')<a href="{{ route('stock-items.edit', $item) }}" class="btn btn-primary">Edit</a>@endcan
        @can('inventory.delete')
            <form method="POST" action="{{ route('stock-items.destroy', $item) }}" data-confirm="Delete stock item {{ $item->name }}?">
                @csrf @method('DELETE')
                <button class="btn btn-outline-danger">Delete</button>
            </form>
        @endcan
    </div>
</div>

<div class="row g-3 mb-3">
    <div class="col-6 col-lg-3"><div class="erp-card p-3 h-100"><div class="small text-secondary">In stock on {{ \Illuminate\Support\Carbon::parse($to)->format('d M Y') }}</div><div class="fs-5 fw-semibold {{ $position->quantity < 0 ? 'text-danger' : '' }}">{{ $q($position->quantity) }}</div></div></div>
    <div class="col-6 col-lg-3"><div class="erp-card p-3 h-100"><div class="small text-secondary">Average cost</div><div class="fs-5 fw-semibold">{{ $m($position->rate) }}</div></div></div>
    <div class="col-6 col-lg-3"><div class="erp-card p-3 h-100"><div class="small text-secondary">Stock value</div><div class="fs-5 fw-semibold">{{ $m($position->value) }}</div></div></div>
    <div class="col-6 col-lg-3"><div class="erp-card p-3 h-100"><div class="small text-secondary">Reorder level</div><div class="fs-5 fw-semibold">{{ $item->reorder_level !== null ? $q(\App\Support\Decimal::toScaled($item->reorder_level, 3)) : '—' }}</div></div></div>
</div>

<div class="row g-3">
    <div class="col-12 col-xl-4">
        <div class="erp-card mb-3">
            <div class="erp-card-header">Details</div>
            <dl class="row mb-0 p-3 small">
                <dt class="col-5">HSN / SAC</dt><dd class="col-7">{{ $item->hsn_sac ?: '—' }}</dd>
                <dt class="col-5">GST rate</dt><dd class="col-7">{{ $item->gst_rate !== null ? \App\Support\Decimal::trim($item->gst_rate).'%' : '—' }}</dd>
                <dt class="col-5">Selling rate</dt><dd class="col-7">{{ $item->sales_rate !== null ? $m(\App\Support\Money::toPaise($item->sales_rate)) : '—' }}</dd>
                <dt class="col-5">Purchase rate</dt><dd class="col-7">{{ $item->purchase_rate !== null ? $m(\App\Support\Money::toPaise($item->purchase_rate)) : '—' }}</dd>
                <dt class="col-5">Sales ledger</dt><dd class="col-7">{{ $item->salesAccount?->name ?? '—' }}</dd>
                <dt class="col-5">Purchase ledger</dt><dd class="col-7">{{ $item->purchaseAccount?->name ?? '—' }}</dd>
                <dt class="col-5">Opening stock</dt><dd class="col-7">{{ $q(\App\Support\Decimal::toScaled($item->opening_quantity, 3)) }} · {{ $m(\App\Support\Money::toPaise($item->opening_value)) }}@if ($item->openingGodown) · {{ $item->openingGodown->name }}@endif</dd>
                @if ($item->description)<dt class="col-5">Description</dt><dd class="col-7">{{ $item->description }}</dd>@endif
            </dl>
        </div>
        <div class="erp-card">
            <div class="erp-card-header">By godown</div>
            <table class="table table-sm mb-0">
                <tbody>
                @forelse ($godowns as $g)
                    @continue(! ($byGodown[$g->id] ?? 0))
                    <tr><td>{{ $g->name }}</td><td class="text-end {{ $byGodown[$g->id] < 0 ? 'text-danger' : '' }}">{{ $q($byGodown[$g->id]) }}</td></tr>
                @empty
                @endforelse
                @if (! array_filter($byGodown))<tr><td class="text-secondary">No stock held.</td></tr>@endif
                </tbody>
            </table>
        </div>
    </div>

    <div class="col-12 col-xl-8">
        <form method="GET" class="erp-card p-3 mb-3">
            <div class="row g-2 align-items-end">
                <div class="col-6 col-md-3"><label class="form-label small" for="from">From</label><input type="date" id="from" name="from" value="{{ $from }}" class="form-control form-control-sm"></div>
                <div class="col-6 col-md-3"><label class="form-label small" for="to">To</label><input type="date" id="to" name="to" value="{{ $to }}" class="form-control form-control-sm"></div>
                <div class="col-8 col-md-4">
                    <label class="form-label small" for="godown">Godown</label>
                    <select id="godown" name="godown" class="form-select form-select-sm">
                        <option value="">All godowns</option>
                        @foreach ($godowns as $g)<option value="{{ $g->id }}" @selected($godownId === $g->id)>{{ $g->name }}</option>@endforeach
                    </select>
                </div>
                <div class="col-4 col-md-2"><button class="btn btn-sm btn-primary w-100">Show</button></div>
            </div>
        </form>

        <div class="erp-card">
            <div class="erp-card-header">Stock movements</div>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0 table-stack">
                    <thead class="table-light"><tr><th>Date</th><th>Particulars</th><th>Document</th><th class="text-end">Inward</th><th class="text-end">Outward</th><th class="text-end">Balance</th></tr></thead>
                    <tbody>
                        <tr class="row-group"><td data-label="Date">{{ \Illuminate\Support\Carbon::parse($from)->format('d-m-Y') }}</td><td data-label="Particulars" colspan="4">Opening balance</td><td data-label="Balance" class="text-end">{{ $q($ledger['opening']) }}</td></tr>
                        @forelse ($ledger['rows'] as $row)
                            <tr>
                                <td data-label="Date" class="text-nowrap">{{ \Illuminate\Support\Carbon::parse($row->date)->format('d-m-Y') }}</td>
                                <td data-label="Particulars">{{ $labels[$row->kind] }}@if ($row->party) · {{ $row->party }}@endif</td>
                                <td data-label="Document">
                                    @if ($row->doc_type === 'invoice')
                                        <a href="{{ route('invoices.show', $row->doc_id) }}">{{ $row->number ?? 'Invoice' }}</a>
                                    @elseif ($row->doc_type === 'delivery_note')
                                        <a href="{{ route('notes.show', $row->doc_id) }}">{{ $row->number }}</a>
                                    @else
                                        <a href="{{ route('stock-journals.show', $row->doc_id) }}">{{ $row->number }}</a>
                                    @endif
                                </td>
                                <td data-label="Inward" class="text-end">{{ $row->quantity > 0 ? $q($row->quantity) : '' }}</td>
                                <td data-label="Outward" class="text-end">{{ $row->quantity < 0 ? $q(-$row->quantity) : '' }}</td>
                                <td data-label="Balance" class="text-end {{ $row->balance < 0 ? 'text-danger' : '' }}">{{ $q($row->balance) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="text-secondary text-center py-3">No movements in this period.</td></tr>
                        @endforelse
                    </tbody>
                    <tfoot>
                        <tr class="row-total"><td colspan="3">Totals and closing</td><td class="text-end">{{ $q($ledger['inward']) }}</td><td class="text-end">{{ $q($ledger['outward']) }}</td><td class="text-end">{{ $q($ledger['closing']) }}</td></tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>
</div>
@endsection
