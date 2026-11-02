@extends('layouts.erp')
@section('title', 'Driver advances')
@php($p = fn ($paise) => \App\Support\Money::format($paise, paiseInput: true))
@php($dc = fn ($paise) => \App\Support\Money::format(abs($paise), paiseInput: true).($paise === 0 ? '' : ($paise > 0 ? ' Dr' : ' Cr')))

@section('content')
<div class="page-header"><div><h1>Driver advances</h1><p class="text-secondary mb-0">Money given to drivers, what they spent on trips, and what is still with them.</p></div></div>
@include('transport.reports._nav')
@include('reports._period', ['csv' => true])

<div class="erp-card">
    <div class="table-responsive">
        <table class="table table-sm align-middle mb-0 table-stack table-report">
            <thead class="table-light"><tr><th>Driver</th><th class="text-end">Opening</th><th class="text-end">Advanced</th><th class="text-end">Spent / settled</th><th class="text-end">Balance with driver</th></tr></thead>
            <tbody>
            @forelse ($rows as $r)
                <tr>
                    <td data-label="Driver"><a href="{{ route('reports.ledger', ['ledger' => $r->driver->account_id, 'from' => $from, 'to' => $to]) }}">{{ $r->driver->name }}</a> @unless ($r->driver->is_active)<span class="badge text-bg-secondary">Inactive</span>@endunless</td>
                    <td data-label="Opening" class="amount">{{ $dc($r->opening) }}</td>
                    <td data-label="Advanced" class="amount">{{ $p($r->advanced) }}</td>
                    <td data-label="Spent / settled" class="amount">{{ $p($r->spent) }}</td>
                    <td data-label="Balance with driver" class="amount fw-semibold {{ $r->balance < 0 ? 'text-danger' : '' }}">{{ $dc($r->balance) }}</td>
                </tr>
            @empty
                <tr><td colspan="5" class="text-center text-secondary py-4">No drivers yet.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
<p class="small text-secondary mt-2">A Dr balance is money the driver still holds; Cr means the company owes the driver. Give advances with a payment voucher to the driver's advance ledger.</p>
@endsection
