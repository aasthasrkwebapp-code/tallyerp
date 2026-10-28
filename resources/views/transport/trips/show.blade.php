@extends('layouts.erp')

@section('title', 'Trip '.$trip->number)

@php($m = fn ($v) => \App\Support\Money::format($v))
@php($p = fn ($paise) => \App\Support\Money::format($paise, paiseInput: true))
@php($badge = ['open' => 'info', 'completed' => 'success', 'cancelled' => 'secondary'])
@php($invoice = $trip->invoice)
@php($billable = $trip->status !== 'cancelled' && (! $invoice || $invoice->status() === \App\Models\Voucher::CANCELLED))

@section('content')
<div class="page-header">
    <div>
        <h1>Trip {{ $trip->number }} <span class="badge text-bg-{{ $badge[$trip->status] }} align-middle">{{ \App\Models\Trip::STATUSES[$trip->status] }}</span></h1>
        <p class="text-secondary mb-0">{{ $trip->origin }} → {{ $trip->destination }} · {{ $trip->vehicle->number }}@if ($trip->driver) · {{ $trip->driver->name }}@endif · {{ $trip->start_date->format('d M Y') }}@if ($trip->end_date) to {{ $trip->end_date->format('d M Y') }}@endif</p>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <a href="{{ route('trips.index') }}" class="btn btn-outline-secondary">All trips</a>
        @if ($trip->status !== 'cancelled')
            @can('transport.create')<a href="{{ route('transport-expenses.create', ['trip' => $trip->id]) }}" class="btn btn-primary"><i class="bi bi-plus-lg me-1"></i>Add expense</a>@endcan
            @can('transport.edit')<a href="{{ route('trips.edit', $trip) }}" class="btn btn-outline-primary">Edit</a>@endcan
            <a href="{{ route('trips.lr', $trip) }}" class="btn btn-outline-secondary" target="_blank"><i class="bi bi-printer me-1"></i>Print LR</a>
        @endif
    </div>
</div>

<div class="row g-3 mb-3">
    <div class="col-6 col-lg-3"><div class="erp-card p-3 h-100"><div class="small text-secondary">Freight {{ $figures->billed ? '(billed)' : '(agreed)' }}</div><div class="fs-5 fw-semibold">{{ $p($figures->freight) }}</div></div></div>
    <div class="col-6 col-lg-3"><div class="erp-card p-3 h-100"><div class="small text-secondary">Expenses</div><div class="fs-5 fw-semibold">{{ $p($figures->expenses) }}</div></div></div>
    <div class="col-6 col-lg-3"><div class="erp-card p-3 h-100"><div class="small text-secondary">Trip profit</div><div class="fs-5 fw-semibold {{ $figures->profit < 0 ? 'text-danger' : 'text-success' }}">{{ $p($figures->profit) }}</div></div></div>
    <div class="col-6 col-lg-3"><div class="erp-card p-3 h-100"><div class="small text-secondary">Distance</div><div class="fs-5 fw-semibold">{{ $figures->km !== null ? number_format($figures->km).' km' : '—' }}</div>@if ($figures->km)<div class="small text-secondary">{{ $p(intdiv($figures->expenses, $figures->km)) }} per km</div>@endif</div></div>
</div>

<div class="row g-3">
    <div class="col-12 col-xl-4">
        <div class="erp-card mb-3">
            <div class="erp-card-header">Details</div>
            <dl class="row mb-0 p-3 small">
                <dt class="col-5">Customer</dt><dd class="col-7">{{ $trip->party?->name ?? '—' }}</dd>
                <dt class="col-5">Route</dt><dd class="col-7">{{ $trip->route?->name ?? '—' }}</dd>
                <dt class="col-5">LR number</dt><dd class="col-7">{{ $trip->lr_number ?: '—' }}@if ($trip->lr_date) <span class="text-secondary">{{ $trip->lr_date->format('d-m-Y') }}</span>@endif</dd>
                <dt class="col-5">Consignee</dt><dd class="col-7">{{ $trip->consignee_name ?: '—' }}</dd>
                <dt class="col-5">E-way bill</dt><dd class="col-7">{{ $trip->eway_bill_no ?: '—' }}@if ($trip->eway_bill_valid_till) <span class="{{ $trip->status === 'open' && $trip->ewayBillExpired() ? 'text-danger fw-semibold' : 'text-secondary' }}">till {{ $trip->eway_bill_valid_till->format('d-m-Y') }}{{ $trip->status === 'open' && $trip->ewayBillExpired() ? ' (expired)' : '' }}</span>@endif</dd>
                <dt class="col-5">Goods</dt><dd class="col-7">{{ $trip->packages ? $trip->packages.' '.($trip->packing ?: 'packages') : '—' }}@if ($trip->goods_value !== null) · {{ $m($trip->goods_value) }}@endif</dd>
                <dt class="col-5">Material</dt><dd class="col-7">{{ $trip->material ?: '—' }}@if ($trip->weight_tonnes !== null) · {{ \App\Support\Decimal::trim($trip->weight_tonnes) }} t @endif</dd>
                <dt class="col-5">Odometer</dt><dd class="col-7">{{ $trip->start_km ?? '—' }} → {{ $trip->end_km ?? '—' }}</dd>
                @if ($trip->narration)<dt class="col-5">Notes</dt><dd class="col-7">{{ $trip->narration }}</dd>@endif
            </dl>
        </div>

        @if ($trip->status === 'open')
            @can('transport.edit')
                <form method="POST" action="{{ route('trips.complete', $trip) }}" class="erp-card p-3 mb-3">
                    @csrf
                    <h2 class="h6 fw-bold">Complete the trip</h2>
                    <div class="row g-2">
                        <div class="col-6"><label for="end_date" class="form-label small">End date</label><input type="date" id="end_date" name="end_date" value="{{ old('end_date', now()->toDateString()) }}" class="form-control form-control-sm @error('end_date') is-invalid @enderror" required></div>
                        <div class="col-6"><label for="end_km" class="form-label small">Odometer at end</label><input id="end_km" name="end_km" value="{{ old('end_km') }}" class="form-control form-control-sm text-end @error('end_km') is-invalid @enderror" inputmode="numeric"></div>
                    </div>
                    @error('end_km')<div class="text-danger small">{{ $message }}</div>@enderror
                    <button class="btn btn-sm btn-success mt-2">Mark completed</button>
                </form>
            @endcan
        @endif

        <div class="erp-card p-3 mb-3">
            <h2 class="h6 fw-bold">Freight bill</h2>
            @if ($invoice && ! $billable)
                <p class="mb-0 small"><a href="{{ route('invoices.show', $invoice) }}">{{ $invoice->displayNumber() }}</a> · {{ $invoice->date->format('d-m-Y') }} · {{ $m($invoice->grand_total) }} @include('accounting.vouchers._status', ['status' => $invoice->status()])</p>
            @elseif ($billable && auth()->user()->can('transport.edit') && auth()->user()->can('sales.create'))
                @if (! $trip->party_account_id)
                    <p class="small text-secondary mb-0">Set the customer on the trip to raise a freight bill.</p>
                @else
                    <form method="POST" action="{{ route('trips.bill', $trip) }}" data-confirm="Raise and post the freight bill?">
                        @csrf
                        <div class="row g-2">
                            <div class="col-6"><label for="bill_date" class="form-label small">Date</label><input type="date" id="bill_date" name="date" value="{{ old('date', ($trip->end_date ?? now())->toDateString()) }}" class="form-control form-control-sm @error('date') is-invalid @enderror"></div>
                            <div class="col-6"><label for="bill_amount" class="form-label small">Freight</label><input id="bill_amount" name="amount" value="{{ old('amount', $trip->freight_amount) }}" class="form-control form-control-sm text-end @error('amount') is-invalid @enderror" inputmode="decimal"></div>
                            <div class="col-6"><label for="gst_rate" class="form-label small">GST %</label>
                                <select id="gst_rate" name="gst_rate" class="form-select form-select-sm">@foreach ($gstRates as $r)<option value="{{ $r }}" @selected(old('gst_rate', '0') === $r)>{{ $r }}%</option>@endforeach</select>
                            </div>
                            <div class="col-6 d-flex align-items-end"><button class="btn btn-sm btn-primary w-100">Raise bill</button></div>
                        </div>
                        <p class="small text-secondary mt-2 mb-0">Posts a sales invoice to the customer (SAC 9965, Freight Income). Use 0% when GST is paid by the customer under reverse charge.</p>
                    </form>
                @endif
            @else
                <p class="small text-secondary mb-0">Not billed.</p>
            @endif
        </div>

        @if ($trip->status !== 'cancelled')
            @can('transport.delete')
                <form method="POST" action="{{ route('trips.cancel', $trip) }}" data-confirm="Cancel trip {{ $trip->number }}?">
                    @csrf
                    <button class="btn btn-sm btn-outline-danger">Cancel trip</button>
                </form>
            @endcan
        @endif
    </div>

    <div class="col-12 col-xl-8">
        <div class="erp-card">
            <div class="erp-card-header">Trip expenses</div>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0 table-stack">
                    <thead class="table-light"><tr><th>Date</th><th>Expense</th><th>Paid from</th><th>Voucher</th><th class="text-end">Amount</th></tr></thead>
                    <tbody>
                    @forelse ($trip->expenses->sortBy('date') as $e)
                        <tr class="{{ $e->status === 'cancelled' ? 'text-decoration-line-through text-secondary' : '' }}">
                            <td data-label="Date" class="text-nowrap">{{ $e->date->format('d-m-Y') }}</td>
                            <td data-label="Expense">{{ $e->categoryLabel() }}@if ($e->quantity) · {{ \App\Support\Decimal::trim($e->quantity) }} L @endif @if ($e->description)<span class="small text-secondary">· {{ $e->description }}</span>@endif</td>
                            <td data-label="Paid from">{{ $e->paidAccount->name }}</td>
                            <td data-label="Voucher">@if ($e->voucher)<a href="{{ route('vouchers.show', $e->voucher) }}">{{ $e->voucher->voucher_number }}</a>@endif</td>
                            <td data-label="Amount" class="amount">{{ $m($e->amount) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="text-secondary text-center py-3">No expenses recorded.</td></tr>
                    @endforelse
                    </tbody>
                    @if ($figures->by_category)
                        <tfoot>
                            @foreach ($figures->by_category as $cat => $amount)
                                <tr><td colspan="4" class="text-end small text-secondary">{{ config("erp.transport_expense_categories.$cat.label") }}</td><td class="amount small">{{ $p($amount) }}</td></tr>
                            @endforeach
                            <tr class="row-total"><td colspan="4">Total expenses</td><td class="amount">{{ $p($figures->expenses) }}</td></tr>
                        </tfoot>
                    @endif
                </table>
            </div>
        </div>
    </div>
</div>
@endsection
