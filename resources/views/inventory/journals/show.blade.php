@extends('layouts.erp')

@section('title', 'Stock Journal '.$journal->number)

@php($m = fn ($p) => \App\Support\Money::format($p, paiseInput: true))

@section('content')
<div class="page-header">
    <div>
        <h1>Stock journal {{ $journal->number }}
            @if ($journal->isPosted())<span class="badge text-bg-success align-middle">Posted</span>@else<span class="badge text-bg-secondary align-middle">Cancelled</span>@endif
        </h1>
        <p class="text-secondary mb-0">{{ \App\Models\StockJournal::PURPOSES[$journal->purpose] ?? $journal->purpose }} · {{ $journal->date->format('d M Y') }}@if ($journal->creator) · by {{ $journal->creator->name }}@endif</p>
    </div>
    <a href="{{ route('stock-journals.index') }}" class="btn btn-outline-secondary">All stock journals</a>
</div>

@if ($journal->dispatchInvoice)
    <div class="alert alert-info">This journal {{ $journal->purpose === 'transfer' ? 'receives goods' : 'writes off a shortage' }} bought in transit on purchase <a href="{{ route('invoices.show', $journal->dispatchInvoice) }}">{{ $journal->dispatchInvoice->displayNumber() }}</a> from {{ $journal->dispatchInvoice->party_name }}.</div>
@endif
@if ($journal->dispatch)
    <div class="alert alert-info">This journal {{ $journal->purpose === 'transfer' ? 'receives goods' : 'writes off a shortage' }} against dispatch <a href="{{ route('stock-journals.show', $journal->dispatch) }}">{{ $journal->dispatch->number }}</a> of goods in transit.</div>
@endif

@unless ($journal->isPosted())
    <div class="alert alert-secondary">Cancelled {{ $journal->cancelled_at?->format('d M Y H:i') }}: {{ $journal->cancellation_reason }}. It no longer moves stock.</div>
@endunless

<div class="erp-card mb-3">
    <div class="table-responsive">
        <table class="table align-middle mb-0 table-stack">
            <thead class="table-light"><tr><th>Item</th><th>Godown</th><th>Direction</th><th class="text-end">Quantity</th><th class="text-end">Rate</th><th class="text-end">Value</th></tr></thead>
            <tbody>
            @foreach ($journal->lines as $line)
                <tr>
                    <td data-label="Item"><a href="{{ route('stock-items.show', $line->stock_item_id) }}">{{ $line->item->name }}</a>@include('inventory._tracking', ['line' => $line])</td>
                    <td data-label="Godown">{{ $line->godown->name }}</td>
                    <td data-label="Direction">{!! $line->direction === 'in' ? '<span class="text-success">In</span>' : '<span class="text-danger">Out</span>' !!}</td>
                    <td data-label="Quantity" class="text-end">{{ \App\Services\Inventory\StockLedger::qty(\App\Support\Decimal::toScaled($line->quantity, 3), $line->item->unit) }}</td>
                    <td data-label="Rate" class="amount">{{ $journal->purpose === 'transfer' ? '' : $m(\App\Support\Money::toPaise($line->rate)) }}</td>
                    <td data-label="Value" class="amount">{{ $line->direction === 'in' && $journal->purpose !== 'transfer' ? $m(\App\Support\Money::toPaise($line->value)) : '' }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
    @if ($journal->narration)<div class="p-3 border-top small"><span class="text-secondary">Narration:</span> {{ $journal->narration }}</div>@endif
</div>

@if ($transit)
    @php($pending = $transit->sum('pending'))
    <div class="erp-card mb-3">
        <div class="erp-card-header d-flex flex-wrap justify-content-between gap-2">
            <span>Goods in transit to {{ $journal->transitTo?->name }}</span>
            @if (! $journal->isPosted())<span class="badge text-bg-secondary">Cancelled</span>@elseif ($pending > 0)<span class="badge text-bg-warning">In transit</span>@else<span class="badge text-bg-success">Received</span>@endif
        </div>
        @php($canReceive = $journal->isPosted() && $pending > 0 && auth()->user()->can('inventory.create'))
        <form method="POST" action="{{ route('stock-journals.receive', $journal) }}">
            @csrf
            <div class="table-responsive">
                <table class="table align-middle mb-0 table-stack">
                    <thead class="table-light"><tr><th>Item</th><th class="text-end">Dispatched</th><th class="text-end">Received</th><th class="text-end">Short</th><th class="text-end">In transit</th>@if ($canReceive)<th class="text-end">Receive now</th><th class="text-end">Short now</th>@endif</tr></thead>
                    <tbody>
                    @foreach ($transit as $t)
                        @php($unit = $t->line->item->unit)
                        <tr>
                            <td data-label="Item">{{ $t->line->item->name }}@include('inventory._tracking', ['line' => $t->line])</td>
                            <td data-label="Dispatched" class="text-end">{{ \App\Services\Inventory\StockLedger::qty($t->dispatched, $unit) }}</td>
                            <td data-label="Received" class="text-end">{{ \App\Services\Inventory\StockLedger::qty($t->received, $unit) }}</td>
                            <td data-label="Short" class="text-end">{{ \App\Services\Inventory\StockLedger::qty($t->short, $unit) }}</td>
                            <td data-label="In transit" class="text-end fw-semibold">{{ \App\Services\Inventory\StockLedger::qty($t->pending, $unit) }}</td>
                            @if ($canReceive)
                                <td data-label="Receive now" class="text-end" style="min-width: 7rem">
                                    @if ($t->pending > 0)<input name="lines[{{ $t->line->id }}][received]" value="{{ old("lines.{$t->line->id}.received", \App\Support\Decimal::trim(\App\Support\Decimal::fromScaled($t->pending, 3))) }}" class="form-control form-control-sm text-end @error("lines.{$t->line->id}.received") is-invalid @enderror" inputmode="decimal" aria-label="Receive {{ $t->line->item->name }}">
                                    @error("lines.{$t->line->id}.received")<div class="invalid-feedback text-start">{{ $message }}</div>@enderror @endif
                                </td>
                                <td data-label="Short now" class="text-end" style="min-width: 7rem">
                                    @if ($t->pending > 0 && $t->line->item->tracking !== 'serial')<input name="lines[{{ $t->line->id }}][short]" value="{{ old("lines.{$t->line->id}.short") }}" class="form-control form-control-sm text-end @error("lines.{$t->line->id}.short") is-invalid @enderror" inputmode="decimal" placeholder="0" aria-label="Short {{ $t->line->item->name }}">
                                    @error("lines.{$t->line->id}.short")<div class="invalid-feedback text-start">{{ $message }}</div>@enderror @endif
                                </td>
                            @endif
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            @if ($canReceive)
                <div class="p-3 border-top">
                    @error('receive')<div class="alert alert-danger small py-2">{{ $message }}</div>@enderror
                    <div class="row g-2 align-items-end">
                        <div class="col-6 col-md-3">
                            <label for="receive_date" class="form-label small">Received on</label>
                            <input type="date" id="receive_date" name="date" value="{{ old('date', now()->toDateString()) }}" class="form-control form-control-sm @error('date') is-invalid @enderror" required>
                            @error('date')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-12 col-md">
                            <label for="receive_narration" class="form-label small">Note</label>
                            <input id="receive_narration" name="narration" value="{{ old('narration') }}" class="form-control form-control-sm" maxlength="1000" placeholder="E.g. LR number, reason for shortage">
                        </div>
                        <div class="col-12 col-md-auto"><button class="btn btn-sm btn-primary w-100">Receive</button></div>
                    </div>
                    <p class="small text-secondary mt-2 mb-0">Received goods move from the transit godown into {{ $journal->transitTo?->name }}. A shortage is written off from the transit godown at average cost.</p>
                </div>
            @endif
        </form>
        @if ($journal->settlements->isNotEmpty())
            <div class="p-3 border-top small">
                <span class="text-secondary">Receipts and shortages:</span>
                @foreach ($journal->settlements as $s)
                    <a href="{{ route('stock-journals.show', $s) }}" class="{{ $s->isPosted() ? '' : 'text-decoration-line-through text-secondary' }}">{{ $s->number }}</a> {{ $s->purpose === 'transfer' ? 'received' : 'short' }} {{ $s->date->format('d M Y') }}@if (! $loop->last) · @endif
                @endforeach
            </div>
        @endif
    </div>
@endif

@if ($journal->isPosted())
    @can('inventory.delete')
        <form method="POST" action="{{ route('stock-journals.cancel', $journal) }}" class="erp-card p-3" style="max-width: 560px" data-confirm="Cancel stock journal {{ $journal->number }}?">
            @csrf
            <label for="reason" class="form-label">Cancel this stock journal</label>
            <div class="input-group">
                <input id="reason" name="reason" class="form-control" placeholder="Reason" required maxlength="255">
                <button class="btn btn-outline-danger">Cancel journal</button>
            </div>
        </form>
    @endcan
@endif
@endsection
