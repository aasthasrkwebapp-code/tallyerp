@extends('layouts.erp')
@section('title', 'Vehicle profitability')
@php($p = fn ($paise) => \App\Support\Money::format($paise, paiseInput: true))
@php($used = collect($categories)->keys()->filter(fn ($c) => $rows->contains(fn ($r) => ($r->by_category[$c] ?? 0) !== 0))->values())

@section('content')
<div class="page-header"><div><h1>Vehicle profitability</h1><p class="text-secondary mb-0">Freight, running costs and cost per km for each vehicle.</p></div></div>
@include('transport.reports._nav')
@include('reports._period', ['csv' => true])

<div class="erp-card">
    <div class="table-responsive">
        <table class="table table-sm align-middle mb-0 table-stack table-report">
            <thead class="table-light"><tr><th>Vehicle</th><th class="text-end">Trips</th><th class="text-end">Km</th><th class="text-end">Freight</th>
                @foreach ($used as $c)<th class="text-end">{{ $categories[$c]['label'] }}</th>@endforeach
                <th class="text-end">Total expenses</th><th class="text-end">Profit</th><th class="text-end">Cost / km</th></tr></thead>
            <tbody>
            @forelse ($rows as $r)
                <tr>
                    <td data-label="Vehicle"><a href="{{ route('transport-reports', ['report' => 'trips', 'from' => $from, 'to' => $to, 'vehicle' => $r->vehicle->id]) }}">{{ $r->vehicle->number }}</a> <span class="small text-secondary">{{ $r->vehicle->type }}</span></td>
                    <td data-label="Trips" class="text-end">{{ $r->trips }}</td>
                    <td data-label="Km" class="text-end">{{ $r->km }}</td>
                    <td data-label="Freight" class="amount">{{ $p($r->freight) }}</td>
                    @foreach ($used as $c)<td data-label="{{ $categories[$c]['label'] }}" class="amount">{{ $p($r->by_category[$c] ?? 0) }}</td>@endforeach
                    <td data-label="Total expenses" class="amount">{{ $p($r->expenses) }}</td>
                    <td data-label="Profit" class="amount fw-semibold {{ $r->profit < 0 ? 'text-danger' : '' }}">{{ $p($r->profit) }}</td>
                    <td data-label="Cost / km" class="amount">{{ $r->cost_per_km !== null ? $p($r->cost_per_km) : '—' }}</td>
                </tr>
            @empty
                <tr><td colspan="{{ 7 + $used->count() }}" class="text-center text-secondary py-4">No vehicles yet.</td></tr>
            @endforelse
            </tbody>
            @if ($rows->isNotEmpty())
                <tfoot><tr class="row-total"><td>Total</td><td data-label="Trips" class="text-end">{{ $rows->sum('trips') }}</td><td data-label="Km" class="text-end">{{ $rows->sum('km') }}</td><td data-label="Freight" class="amount">{{ $p($rows->sum('freight')) }}</td>
                    @foreach ($used as $c)<td data-label="{{ $categories[$c]['label'] }}" class="amount">{{ $p($rows->sum(fn ($r) => $r->by_category[$c] ?? 0)) }}</td>@endforeach
                    <td data-label="Total expenses" class="amount">{{ $p($rows->sum('expenses')) }}</td><td data-label="Profit" class="amount">{{ $p($rows->sum('profit')) }}</td><td></td></tr></tfoot>
            @endif
        </table>
    </div>
</div>
<p class="small text-secondary mt-2">Expenses include those recorded against a vehicle without a trip (maintenance, insurance, permits) in the period.</p>
@endsection
