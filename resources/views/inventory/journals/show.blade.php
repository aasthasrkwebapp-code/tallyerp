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
                    <td data-label="Item"><a href="{{ route('stock-items.show', $line->stock_item_id) }}">{{ $line->item->name }}</a></td>
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
