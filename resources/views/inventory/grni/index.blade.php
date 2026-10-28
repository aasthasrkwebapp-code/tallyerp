@extends('layouts.erp')

@section('title', 'GRNI Provision')

@php($p = fn ($paise) => \App\Support\Money::format($paise, paiseInput: true))
@php($q = fn ($milli, $unit) => \App\Services\Inventory\StockLedger::qty($milli, $unit))

@section('content')
<div class="page-header">
    <div>
        <h1>Goods received not invoiced</h1>
        <p class="text-secondary mb-0">Goods receipt notes not yet billed on {{ \Illuminate\Support\Carbon::parse($date)->format('d M Y') }}, and the provision that books them as purchases</p>
    </div>
    <a href="{{ route('notes.pending', ['kind' => 'receipt_note']) }}" class="btn btn-outline-secondary">Received, not billed</a>
</div>

<form method="GET" class="erp-card p-3 mb-3 no-print">
    <div class="row g-2 align-items-end">
        <div class="col-6 col-md-3">
            <label class="form-label small" for="date">As on</label>
            <input id="date" type="date" name="date" value="{{ $date }}" class="form-control form-control-sm">
        </div>
        <div class="col-6 col-md-auto"><button class="btn btn-sm btn-primary">Show</button></div>
    </div>
</form>

<div class="row g-3 mb-3">
    <div class="col-12 col-lg-5">
        <div class="erp-card h-100">
            <div class="erp-card-header">Provision on {{ \Illuminate\Support\Carbon::parse($date)->format('d M Y') }}</div>
            <div class="p-3">
                <table class="table table-sm mb-3">
                    <tbody>
                    @foreach ($preview['ledgers'] as $l)
                        <tr><td>Dr {{ $l->name }} <span class="text-secondary small">{{ $l->lines }} {{ \Illuminate\Support\Str::plural('line', $l->lines) }}</span></td><td class="amount">{{ $p($l->amount) }}</td></tr>
                    @endforeach
                    <tr class="fw-semibold"><td>Cr Goods Received Not Invoiced</td><td class="amount">{{ $p($preview['total']) }}</td></tr>
                    </tbody>
                </table>
                @if ($posted)
                    <p class="mb-0"><i class="bi bi-check-circle text-success"></i> Posted as <a href="{{ route('vouchers.show', $posted->voucher_id) }}">{{ $posted->voucher?->voucher_number }}</a> for {{ \App\Support\Money::format($posted->amount) }}@if ($posted->reversal), reversed by <a href="{{ route('vouchers.show', $posted->reversal) }}">{{ $posted->reversal->voucher_number }}</a> on {{ $posted->reversal->date->format('d M Y') }}@endif.</p>
                @elseif ($preview['total'] > 0)
                    @can('purchases.create')
                        <form method="POST" action="{{ route('grni.store') }}" data-confirm="Post the provision of {{ $p($preview['total']) }}?">
                            @csrf
                            <input type="hidden" name="date" value="{{ $date }}">
                            <div class="form-check mb-2">
                                <input type="hidden" name="reverse" value="0">
                                <input class="form-check-input" type="checkbox" name="reverse" value="1" id="reverse" checked>
                                <label class="form-check-label" for="reverse">Reverse it on {{ \Illuminate\Support\Carbon::parse($date)->addDay()->format('d M Y') }}</label>
                            </div>
                            @error('date')<div class="alert alert-danger small py-2">{{ $message }}</div>@enderror
                            <button class="btn btn-primary btn-sm">Post provision</button>
                        </form>
                    @endcan
                @else
                    <p class="mb-0 text-secondary">Nothing to provide for: every valued goods receipt note is billed on this date.</p>
                @endif
                <p class="small text-secondary mt-3 mb-0">A goods receipt note adds to closing stock, but the purchase is booked only with the supplier's bill. The provision books the unbilled value at the period end so profit is not overstated. Reversing it the next day lets the bill book the purchase as usual.</p>
            </div>
        </div>
    </div>
    <div class="col-12 col-lg-7">
        <div class="erp-card h-100">
            <div class="erp-card-header">Unbilled lines <span class="text-secondary small">{{ $preview['rows']->count() }} · {{ $p($preview['total']) }}</span></div>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0 table-stack">
                    <thead class="table-light"><tr><th>GRN</th><th>Supplier</th><th>Item</th><th class="text-end">Not billed</th><th class="text-end">Value</th></tr></thead>
                    <tbody>
                    @forelse ($preview['rows'] as $r)
                        <tr>
                            <td data-label="GRN"><a href="{{ route('notes.show', $r->note) }}">{{ $r->note->number }}</a> <span class="small text-secondary">{{ $r->note->date->format('d M Y') }}</span></td>
                            <td data-label="Supplier">{{ $r->note->party_name }}</td>
                            <td data-label="Item">{{ $r->line->description }}</td>
                            <td data-label="Not billed" class="text-end">{{ $q($r->pending, $r->line->item?->unit) }}</td>
                            <td data-label="Value" class="amount">{{ $p($r->value) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="text-secondary text-center py-3">No unbilled goods receipt notes on this date.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
            @if ($preview['unvalued']->isNotEmpty())
                <div class="alert alert-warning m-3 small mb-3">{{ $preview['unvalued']->count() }} unbilled {{ \Illuminate\Support\Str::plural('line', $preview['unvalued']->count()) }} have no rate and are left out:
                    {{ $preview['unvalued']->map(fn ($r) => $r->note->number.' '.$r->line->description)->implode(', ') }}.</div>
            @endif
        </div>
    </div>
</div>

<div class="erp-card">
    <div class="erp-card-header">Provisions posted</div>
    <div class="table-responsive">
        <table class="table table-sm align-middle mb-0 table-stack">
            <thead class="table-light"><tr><th>Date</th><th class="text-end">Amount</th><th>Journal</th><th>Reversal</th><th>Status</th><th class="no-print"></th></tr></thead>
            <tbody>
            @forelse ($provisions as $pr)
                <tr>
                    <td data-label="Date"><a href="{{ route('grni.index', ['date' => $pr->date->toDateString()]) }}">{{ $pr->date->format('d M Y') }}</a></td>
                    <td data-label="Amount" class="amount">{{ \App\Support\Money::format($pr->amount) }}</td>
                    <td data-label="Journal">@if ($pr->voucher)<a href="{{ route('vouchers.show', $pr->voucher) }}">{{ $pr->voucher->voucher_number }}</a>@endif</td>
                    <td data-label="Reversal">@if ($pr->reversal)<a href="{{ route('vouchers.show', $pr->reversal) }}">{{ $pr->reversal->voucher_number }}</a> {{ $pr->reversal->date->format('d M Y') }}@else<span class="text-secondary">Not reversed</span>@endif</td>
                    <td data-label="Status">@if ($pr->status === 'posted')<span class="badge text-bg-success">Posted</span>@else<span class="badge text-bg-secondary" title="{{ $pr->cancellation_reason }}">Cancelled</span>@endif</td>
                    <td data-label="" class="text-end no-print">
                        @if ($pr->status === 'posted')
                            @can('purchases.delete')
                                <form method="POST" action="{{ route('grni.cancel', $pr) }}" class="d-inline-flex gap-1" data-confirm="Cancel this provision and its vouchers?">
                                    @csrf
                                    <input name="reason" class="form-control form-control-sm" placeholder="Reason" required maxlength="255" aria-label="Reason for cancelling">
                                    <button class="btn btn-sm btn-outline-danger">Cancel</button>
                                </form>
                            @endcan
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="6" class="text-secondary text-center py-3">No provisions yet.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
<div class="mt-3">{{ $provisions->links() }}</div>
@endsection
