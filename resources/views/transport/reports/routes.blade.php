@extends('layouts.erp')
@section('title', 'Route profitability')
@php($p = fn ($paise) => \App\Support\Money::format($paise, paiseInput: true))

@section('content')
<div class="page-header"><div><h1>Route profitability</h1><p class="text-secondary mb-0">Trips, freight and profit by route.</p></div></div>
@include('transport.reports._nav')
@include('reports._period', ['csv' => true])

<div class="erp-card">
    <div class="table-responsive">
        <table class="table table-sm align-middle mb-0 table-stack table-report">
            <thead class="table-light"><tr><th>Route</th><th class="text-end">Trips</th><th class="text-end">Km</th><th class="text-end">Freight</th><th class="text-end">Expenses</th><th class="text-end">Profit</th><th class="text-end">Margin</th></tr></thead>
            <tbody>
            @forelse ($rows as $r)
                <tr>
                    <td data-label="Route">{{ $r->label }}</td>
                    <td data-label="Trips" class="text-end">{{ $r->trips }}</td>
                    <td data-label="Km" class="text-end">{{ $r->km }}</td>
                    <td data-label="Freight" class="amount">{{ $p($r->freight) }}</td>
                    <td data-label="Expenses" class="amount">{{ $p($r->expenses) }}</td>
                    <td data-label="Profit" class="amount fw-semibold {{ $r->profit < 0 ? 'text-danger' : '' }}">{{ $p($r->profit) }}</td>
                    <td data-label="Margin" class="text-end">{{ $r->freight > 0 ? number_format($r->profit * 100 / $r->freight, 1).'%' : '—' }}</td>
                </tr>
            @empty
                <tr><td colspan="7" class="text-center text-secondary py-4">No trips started in this period.</td></tr>
            @endforelse
            </tbody>
            @if ($rows->isNotEmpty())
                <tfoot><tr class="row-total"><td>Total</td><td data-label="Trips" class="text-end">{{ $rows->sum('trips') }}</td><td data-label="Km" class="text-end">{{ $rows->sum('km') }}</td><td data-label="Freight" class="amount">{{ $p($rows->sum('freight')) }}</td><td data-label="Expenses" class="amount">{{ $p($rows->sum('expenses')) }}</td><td data-label="Profit" class="amount">{{ $p($rows->sum('profit')) }}</td><td data-label=""></td></tr></tfoot>
            @endif
        </table>
    </div>
</div>
@endsection
