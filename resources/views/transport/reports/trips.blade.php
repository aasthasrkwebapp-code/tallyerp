@extends('layouts.erp')
@section('title', 'Trip profit')
@php($p = fn ($paise) => \App\Support\Money::format($paise, paiseInput: true))

@section('content')
<div class="page-header"><div><h1>Trip profit</h1><p class="text-secondary mb-0">Freight earned less expenses recorded on each trip.</p></div></div>
@include('transport.reports._nav')
@component('reports._period', ['from' => $from, 'to' => $to, 'csv' => true])
    <div class="col-12 col-md-3">
        <label class="form-label small" for="f-vehicle">Vehicle</label>
        <select id="f-vehicle" name="vehicle" class="form-select form-select-sm">
            <option value="">All vehicles</option>
            @foreach ($vehicles as $v)<option value="{{ $v->id }}" @selected((int) request('vehicle') === $v->id)>{{ $v->number }}</option>@endforeach
        </select>
    </div>
@endcomponent

<div class="erp-card">
    <div class="table-responsive">
        <table class="table table-sm align-middle mb-0 table-stack table-report">
            <thead class="table-light"><tr><th>Trip</th><th>Date</th><th>Vehicle</th><th>Route</th><th>Customer</th><th class="text-end">Km</th><th class="text-end">Freight</th><th class="text-end">Expenses</th><th class="text-end">Profit</th></tr></thead>
            <tbody>
            @forelse ($rows as $r)
                <tr>
                    <td data-label="Trip"><a href="{{ route('trips.show', $r->trip) }}">{{ $r->trip->number }}</a></td>
                    <td data-label="Date">{{ $r->trip->start_date->format('d M Y') }}</td>
                    <td data-label="Vehicle">{{ $r->trip->vehicle->number }}</td>
                    <td data-label="Route">{{ $r->trip->origin }} – {{ $r->trip->destination }}</td>
                    <td data-label="Customer">{{ $r->trip->party?->name ?? '—' }}</td>
                    <td data-label="Km" class="text-end">{{ $r->km ?? '—' }}</td>
                    <td data-label="Freight" class="amount">{{ $p($r->freight) }} @unless ($r->billed)<span class="badge text-bg-warning">Unbilled</span>@endunless</td>
                    <td data-label="Expenses" class="amount">{{ $p($r->expenses) }}</td>
                    <td data-label="Profit" class="amount fw-semibold {{ $r->profit < 0 ? 'text-danger' : '' }}">{{ $p($r->profit) }}</td>
                </tr>
            @empty
                <tr><td colspan="9" class="text-center text-secondary py-4">No trips started in this period.</td></tr>
            @endforelse
            </tbody>
            @if ($rows->isNotEmpty())
                <tfoot><tr class="row-total"><td colspan="5">Total ({{ $rows->count() }} trips)</td><td data-label="Km" class="text-end">{{ $rows->sum(fn ($r) => $r->km ?? 0) }}</td><td data-label="Freight" class="amount">{{ $p($rows->sum('freight')) }}</td><td data-label="Expenses" class="amount">{{ $p($rows->sum('expenses')) }}</td><td data-label="Profit" class="amount">{{ $p($rows->sum('profit')) }}</td></tr></tfoot>
            @endif
        </table>
    </div>
</div>
<p class="small text-secondary mt-2">Freight is the taxable value of the posted freight bill, or the trip's agreed freight while it is unbilled. Cancelled trips and expenses are left out.</p>
@endsection
