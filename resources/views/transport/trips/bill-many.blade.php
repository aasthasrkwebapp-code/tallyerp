@extends('layouts.erp')

@section('title', 'Bill several trips')

@section('content')
@php($m = fn ($v) => \App\Support\Money::format($v))
<div class="page-header">
    <div>
        <h1>Bill several trips</h1>
        <p class="text-secondary mb-0">One sales invoice with a line for each trip ticked. Only completed trips that are not yet billed are listed.</p>
    </div>
    <a href="{{ route('trips.index') }}" class="btn btn-outline-secondary">Trips</a>
</div>

@if ($customers->isEmpty())
    <div class="alert alert-info">No completed trips have a customer yet.</div>
@else
<form method="GET" class="erp-card p-3 mb-3">
    <div class="row g-2 align-items-end">
        <div class="col-8 col-md-5">
            <label for="party" class="form-label">Customer</label>
            <select id="party" name="party" class="form-select" onchange="this.form.submit()">
                @foreach ($customers as $c)<option value="{{ $c->id }}" @selected($party?->id === $c->id)>{{ $c->name }}</option>@endforeach
            </select>
        </div>
        <div class="col-4 col-md-2"><button class="btn btn-outline-primary w-100">Show</button></div>
    </div>
</form>

<form method="POST" action="{{ route('trips.bill-many.store') }}" data-confirm="Raise and post one freight bill for the ticked trips?">
    @csrf
    <input type="hidden" name="party" value="{{ $party->id }}">
    @if ($errors->any())<div class="alert alert-danger">@foreach ($errors->all() as $e)<div>{{ $e }}</div>@endforeach</div>@endif
    <div class="erp-card mb-3">
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0 table-stack">
                <thead class="table-light"><tr><th style="width: 2.5rem"><input type="checkbox" class="form-check-input" aria-label="Tick all" onclick="document.querySelectorAll('.trip-tick').forEach(c => c.checked = this.checked)"></th><th>Trip</th><th>Date</th><th>Route</th><th>Vehicle</th><th>LR</th><th class="text-end">Freight</th></tr></thead>
                <tbody>
                @forelse ($trips as $t)
                    <tr>
                        <td data-label="Bill"><input type="checkbox" name="trips[]" value="{{ $t->id }}" class="form-check-input trip-tick" aria-label="Bill trip {{ $t->number }}" @checked(in_array($t->id, array_map('intval', (array) old('trips', [])), true))></td>
                        <td data-label="Trip"><a href="{{ route('trips.show', $t) }}">{{ $t->number }}</a></td>
                        <td data-label="Date">{{ $t->start_date->format('d-m-Y') }}@if ($t->end_date && ! $t->end_date->equalTo($t->start_date)) – {{ $t->end_date->format('d-m-Y') }}@endif</td>
                        <td data-label="Route">{{ $t->origin }} → {{ $t->destination }}</td>
                        <td data-label="Vehicle">{{ $t->vehicle->number }}</td>
                        <td data-label="LR">{{ $t->lr_number }}</td>
                        <td data-label="Freight" class="text-end"><input name="amounts[{{ $t->id }}]" inputmode="decimal" aria-label="Freight for {{ $t->number }}" class="form-control form-control-sm text-end ms-auto @error("amounts.$t->id") is-invalid @enderror" style="max-width: 9rem" value="{{ old("amounts.$t->id", $t->freight_amount) }}"></td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="text-center text-secondary py-4">{{ $party->name }} has no unbilled completed trips.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
    @if ($trips->isNotEmpty())
        <div class="erp-card p-3">
            <div class="row g-2 align-items-end">
                <div class="col-6 col-md-3"><label for="date" class="form-label">Bill date</label><input type="date" id="date" name="date" value="{{ old('date', now()->toDateString()) }}" class="form-control @error('date') is-invalid @enderror"></div>
                <div class="col-6 col-md-2"><label for="gst_rate" class="form-label">GST %</label>
                    <select id="gst_rate" name="gst_rate" class="form-select">@foreach ($gstRates as $r)<option value="{{ $r }}" @selected(old('gst_rate', '0') === $r)>{{ $r }}%</option>@endforeach</select>
                </div>
                <div class="col-12 col-md-3"><button class="btn btn-primary w-100">Raise one bill</button></div>
            </div>
            <p class="small text-secondary mt-2 mb-0">Posts a sales invoice to {{ $party->name }} with SAC 9965 on Freight Income. Use 0% when the customer pays GST under reverse charge.</p>
        </div>
    @endif
</form>
@endif
@endsection
