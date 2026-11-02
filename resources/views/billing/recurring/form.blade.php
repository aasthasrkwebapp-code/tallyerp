@extends('layouts.erp')

@section('title', $recurring->exists ? 'Alter '.$recurring->name : 'New Recurring Invoice')

@php($r = $recurring)
@php($v = fn ($name, $default = null) => old($name, $r->{$name} instanceof \Carbon\CarbonInterface ? $r->{$name}->toDateString() : ($r->{$name} ?? $default)))

@section('content')
<div class="page-header">
    <div>
        <h1>{{ $r->exists ? 'Alter recurring invoice' : 'Make recurring' }}</h1>
        <p class="text-secondary mb-0">
            @if ($invoice)
                From {{ mb_strtolower($invoice->config()['label']) }} {{ $invoice->displayNumber() }} to {{ $invoice->party_name }} for {{ \App\Support\Money::format($invoice->grand_total, true) }}. Each invoice copies its party, lines, rates, addresses and terms, with a new date and number.
            @else
                {{ $r->config()['label'] }} to {{ $r->party?->name }} for {{ \App\Support\Money::format($r->total, true) }}. To change the lines, make a new recurring invoice from an updated invoice.
            @endif
        </p>
    </div>
    <a href="{{ $r->exists ? route('recurring-invoices.show', $r) : route('invoices.show', $invoice) }}" class="btn btn-outline-secondary">Back</a>
</div>

<form method="POST" action="{{ $r->exists ? route('recurring-invoices.update', $r) : route('recurring-invoices.store') }}" class="erp-card p-3 p-md-4" style="max-width: 760px">
    @csrf
    @if ($r->exists) @method('PUT') @else <input type="hidden" name="invoice_id" value="{{ $invoice->id }}"> @endif
    @error('invoice')<div class="alert alert-danger small">{{ $message }}</div>@enderror
    <div class="mb-3">
        <label for="name" class="form-label">Name</label>
        <input id="name" name="name" value="{{ $v('name') }}" class="form-control @error('name') is-invalid @enderror" required maxlength="100">
        @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
    <div class="row g-3 mb-3">
        <div class="col-6 col-md-4">
            <label for="frequency" class="form-label">Repeats</label>
            <select id="frequency" name="frequency" class="form-select @error('frequency') is-invalid @enderror">
                @foreach (\App\Models\RecurringVoucher::FREQUENCIES as $k => $label)<option value="{{ $k }}" @selected($v('frequency', 'monthly') === $k)>{{ $label }}</option>@endforeach
            </select>
            @error('frequency')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <div class="col-6 col-md-4">
            <label for="start_date" class="form-label">First invoice on</label>
            <input type="date" id="start_date" name="start_date" value="{{ $v('start_date') }}" class="form-control @error('start_date') is-invalid @enderror" required>
            @error('start_date')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <div class="col-6 col-md-4">
            <label for="end_date" class="form-label">Last date <span class="text-secondary small">(optional)</span></label>
            <input type="date" id="end_date" name="end_date" value="{{ $v('end_date') }}" class="form-control @error('end_date') is-invalid @enderror">
            @error('end_date')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        @if ($r->exists)
            <div class="col-6 col-md-4">
                <label for="next_date" class="form-label">Next invoice on</label>
                <input type="date" id="next_date" name="next_date" value="{{ $v('next_date') }}" class="form-control @error('next_date') is-invalid @enderror">
                @error('next_date')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
        @endif
        <div class="col-6 col-md-4">
            <label for="due_days" class="form-label">Due after (days)</label>
            <input id="due_days" name="due_days" value="{{ $v('due_days') }}" class="form-control @error('due_days') is-invalid @enderror" inputmode="numeric" placeholder="Party's credit period">
            @error('due_days')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <div class="col-12 col-md-4">
            <label for="mode" class="form-label">Each invoice is</label>
            <select id="mode" name="mode" class="form-select">
                @foreach (\App\Models\RecurringVoucher::MODES as $k => $label)<option value="{{ $k }}" @selected($v('mode', 'post') === $k)>{{ $label }}</option>@endforeach
            </select>
        </div>
    </div>
    @if ($r->exists)
        <div class="form-check mb-3">
            <input type="hidden" name="is_active" value="0">
            <input class="form-check-input" type="checkbox" name="is_active" value="1" id="is_active" @checked($v('is_active', true))>
            <label class="form-check-label" for="is_active">Active (untick to pause)</label>
        </div>
    @endif
    <p class="small text-secondary">Monthly and longer schedules keep the first date's day of the month, using the month's last day when it is shorter. A date that can't be saved, for example in a locked period or for want of stock, is logged and tried again on the next run.</p>
    <div class="d-flex gap-2">
        <button class="btn btn-primary">{{ $r->exists ? 'Save changes' : 'Save recurring invoice' }}</button>
        <a href="{{ $r->exists ? route('recurring-invoices.show', $r) : route('invoices.show', $invoice) }}" class="btn btn-outline-secondary">Cancel</a>
    </div>
</form>
@endsection
