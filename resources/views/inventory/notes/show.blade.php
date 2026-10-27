@extends('layouts.erp')

@php($user = auth()->user())
@php($q = fn ($milli, $p) => \App\Services\Inventory\StockLedger::qty($milli, $p->line->item->unit))
@php($toInvoice = $progress->sum('pending'))

@section('title', $config['label'].' '.$note->number)

@section('content')
<div class="page-header">
    <div>
        <h1>{{ $config['label'] }} {{ $note->number }} @include('inventory.notes._status', ['label' => $label])</h1>
        <p class="text-secondary mb-0">{{ $note->date->format('d M Y') }} · {{ $note->party_name }} · Entered by {{ $note->creator?->name ?? '—' }}</p>
    </div>
    <div class="d-flex flex-wrap gap-2">
        @if ($note->isPosted() && $toInvoice > 0 && $user->can($config['module'].'.create'))
            <a href="{{ route('invoices.create', ['kind' => $config['invoice_kind'], 'note' => $note->id]) }}" class="btn btn-primary"><i class="bi bi-receipt me-1"></i>{{ $config['invoice_kind'] === 'sales' ? 'Make sales invoice' : 'Make purchase bill' }}</a>
        @endif
        <a href="{{ route('notes.print', $note) }}" class="btn btn-outline-secondary" target="_blank" rel="noopener"><i class="bi bi-printer me-1"></i>Print</a>
        <a href="{{ route('notes.pdf', $note) }}" class="btn btn-outline-secondary"><i class="bi bi-file-earmark-pdf me-1"></i>PDF</a>
        @if ($note->isPosted() && $user->can($config['module'].'.delete'))
            <button class="btn btn-outline-danger" data-bs-toggle="collapse" data-bs-target="#cancel-panel" aria-expanded="false">Cancel</button>
        @endif
    </div>
</div>

@error('note')<div class="alert alert-danger">{{ $message }}</div>@enderror

@if ($note->isPosted())
    <div class="collapse" id="cancel-panel">
        <form method="POST" action="{{ route('notes.cancel', $note) }}" class="erp-card p-3 mb-3" style="max-width: 560px" data-confirm="Cancel {{ $note->number }}? Its stock movement is reversed.">
            @csrf
            <label for="reason" class="form-label">Reason for cancelling</label>
            <input id="reason" name="reason" class="form-control mb-2" required maxlength="255">
            <button class="btn btn-danger">Cancel {{ mb_strtolower($config['label']) }}</button>
        </form>
    </div>
@else
    <div class="alert alert-danger small">Cancelled {{ $note->cancelled_at?->format('d M Y H:i') }}: {{ $note->cancellation_reason }}. It no longer moves stock.</div>
@endif

<div class="erp-card p-3 mb-3">
    <div class="row g-2 small">
        <div class="col-12 col-md-4"><div class="text-secondary">{{ $config['direction'] === 'out' ? 'Customer' : 'Supplier' }}</div><span class="fw-semibold">{{ $note->party_name }}</span><div style="white-space: pre-line">{{ $note->party?->partyDetails?->fullAddress() }}</div></div>
        <div class="col-6 col-md-4"><div class="text-secondary">{{ $config['direction'] === 'out' ? 'Vehicle / LR no.' : "Supplier's challan" }}</div>{{ $note->reference ?? '—' }} {{ $note->reference_date?->format('d-m-Y') }}</div>
        <div class="col-6 col-md-4"><div class="text-secondary">Stock</div>{{ $config['direction'] === 'out' ? 'Out of' : 'Into' }} {{ $note->items->pluck('godown.name')->unique()->implode(', ') }}</div>
    </div>
</div>

<div class="erp-card mb-3">
    <div class="table-responsive">
        <table class="table align-middle mb-0 table-stack">
            <thead class="table-light"><tr><th>#</th><th>Item</th><th>Godown</th><th class="text-end">Quantity</th><th class="text-end">{{ $config['invoice_kind'] === 'sales' ? 'Invoiced' : 'Billed' }}</th><th class="text-end">To {{ $config['invoice_kind'] === 'sales' ? 'invoice' : 'bill' }}</th><th class="text-end">Rate</th></tr></thead>
            <tbody>
            @foreach ($progress as $p)
                <tr>
                    <td data-label="#">{{ $p->line->line_no }}</td>
                    <td data-label="Item"><a href="{{ route('stock-items.show', $p->line->stock_item_id) }}">{{ $p->line->description }}</a>@include('inventory._tracking', ['line' => $p->line])
                        @if ($p->line->orderItem)<div class="small"><a href="{{ route('orders.show', $p->line->orderItem->order_id) }}">Order {{ $p->line->orderItem->order->number }}</a></div>@endif</td>
                    <td data-label="Godown">{{ $p->line->godown->name }}</td>
                    <td data-label="Quantity" class="text-end">{{ $q($p->quantity, $p) }}</td>
                    <td data-label="Invoiced" class="text-end">{{ $q($p->invoiced, $p) }}</td>
                    <td data-label="To invoice" class="text-end fw-semibold">{{ $q($p->pending, $p) }}</td>
                    <td data-label="Rate" class="amount">{{ \App\Support\Money::format($p->line->rate) }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
    @if ($note->narration)<div class="p-3 border-top small"><span class="text-secondary">Narration:</span> {{ $note->narration }}</div>@endif
</div>

<div class="erp-card">
    <div class="erp-card-header">{{ $config['invoice_kind'] === 'sales' ? 'Sales invoices' : 'Purchase bills' }} against this note</div>
    <div class="table-responsive">
        <table class="table table-sm mb-0 table-stack small">
            <thead class="table-light"><tr><th>Number</th><th>Date</th><th>Status</th><th class="text-end">Total</th></tr></thead>
            <tbody>
            @forelse ($invoices as $inv)
                <tr>
                    <td data-label="Number"><a href="{{ route('invoices.show', $inv) }}">{{ $inv->displayNumber() }}</a></td>
                    <td data-label="Date">{{ $inv->date->format('d-m-Y') }}</td>
                    <td data-label="Status">@include('accounting.vouchers._status', ['status' => $inv->status()])</td>
                    <td data-label="Total" class="amount">{{ \App\Support\Money::format($inv->grand_total) }}</td>
                </tr>
            @empty
                <tr><td colspan="4" class="text-secondary text-center py-3">None yet.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
