@extends('layouts.erp')

@section('title', 'E-way Bills')

@section('content')
@php($m = fn ($v) => \App\Support\Money::format($v))
<div class="page-header">
    <div>
        <h1>E-way bills</h1>
        <p class="text-secondary mb-0">Sales invoices of goods over ₹{{ number_format(\App\Services\Gst\EwayBillJson::THRESHOLD) }}, and any with e-way bill details. Tick those with transport details and download one JSON for the portal's bulk generation.</p>
    </div>
</div>

<form method="GET" class="erp-card p-3 mb-3 no-print">
    <div class="row g-2 align-items-end">
        <div class="col-6 col-md-3"><label for="month" class="form-label">Month</label><input type="month" id="month" name="month" value="{{ $month }}" class="form-control"></div>
        <div class="col-6 col-md-3"><label for="show" class="form-label">Invoices</label>
            <select id="show" name="show" class="form-select"><option value="">Needing an e-way bill</option><option value="all" @selected($show === 'all')>All sales invoices</option></select></div>
        <div class="col-12 col-md-2"><button class="btn btn-outline-primary w-100">Show</button></div>
    </div>
</form>

@error('invoices')<div class="alert alert-danger">@foreach ($errors->get('invoices') as $list)@foreach ((array) $list as $e)<div>{{ $e }}</div>@endforeach @endforeach</div>@enderror
@error('ids')<div class="alert alert-danger">{{ $message }}</div>@enderror

<form method="GET" action="{{ route('gst.eway-bills.json') }}">
    <div class="erp-card">
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0 table-stack">
                <thead class="table-light"><tr><th style="width: 2.5rem"></th><th>Invoice</th><th>Date</th><th>Customer</th><th class="text-end">Value</th><th>Transport</th><th>E-way bill</th></tr></thead>
                <tbody>
                @forelse ($invoices as $i)
                    @php($e = $i->ewayBill)
                    @php($problems = $eway->problems($i))
                    <tr>
                        <td data-label="Include">@if ($e && ! $e->ewb_no && ! $problems)<input type="checkbox" name="ids[]" value="{{ $i->id }}" class="form-check-input" aria-label="Include {{ $i->displayNumber() }}" checked>@endif</td>
                        <td data-label="Invoice"><a href="{{ route('invoices.show', $i) }}#eway-bill">{{ $i->displayNumber() }}</a></td>
                        <td data-label="Date">{{ $i->date->format('d-m-Y') }}</td>
                        <td data-label="Customer">{{ $i->party_name }}</td>
                        <td data-label="Value" class="amount">{{ $m($i->grand_total) }}</td>
                        <td data-label="Transport"><div>@if ($e){{ \App\Models\EwayBill::MODES[$e->trans_mode][0] }} · {{ $e->distance_km }} km{{ $e->vehicle_no ? ' · '.$e->vehicle_no : '' }}@else<span class="text-secondary">Not entered</span>@endif
                            @if ($problems)<div class="small text-danger">{{ implode(' ', $problems) }}</div>@endif</div></td>
                        <td data-label="E-way bill">@if ($e?->ewb_no){{ $e->ewb_no }}@if ($e->valid_till) <span class="small text-secondary">till {{ $e->valid_till->format('d-m-Y') }}</span>@endif @if ($e->isExpired())<span class="badge text-bg-danger">Expired</span>@endif @else<span class="badge text-bg-warning">Pending</span>@endif</td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="text-center text-secondary py-4">No sales invoices need an e-way bill this month.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
    @if ($invoices->contains(fn ($i) => $i->ewayBill && ! $i->ewayBill->ewb_no))
        <button class="btn btn-primary mt-3"><i class="bi bi-filetype-json me-1"></i>Download JSON for ticked invoices</button>
    @endif
</form>
@endsection
