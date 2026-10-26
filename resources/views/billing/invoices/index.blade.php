@extends('layouts.erp')

@section('title', $config['plural'])

@section('content')
<div class="page-header">
    <div>
        <h1>{{ $config['plural'] }}</h1>
        <p class="text-secondary mb-0">Posted total in this view: <strong>{{ \App\Support\Money::format($postedTotal, true, true) }}</strong></p>
    </div>
    @can($config['module'].'.create')
        <a href="{{ route('invoices.create', ['kind' => $kind]) }}" class="btn btn-primary" data-shortcut="c"><i class="bi bi-plus-lg me-1"></i>New {{ mb_strtolower($config['label']) }}</a>
    @endcan
</div>

<form method="GET" class="erp-card p-3 mb-3">
    <input type="hidden" name="kind" value="{{ $kind }}">
    <div class="row g-2 align-items-end">
        <div class="col-12 col-md-4">
            <label class="form-label small" for="q">Search</label>
            <input id="q" name="q" value="{{ $filters['q'] ?? '' }}" class="form-control form-control-sm" placeholder="Number, party or reference">
        </div>
        <div class="col-6 col-md-2">
            <label class="form-label small" for="status">Status</label>
            <select id="status" name="status" class="form-select form-select-sm">
                <option value="">All</option>
                @foreach (\App\Models\Voucher::STATUSES as $k => $label)<option value="{{ $k }}" @selected(($filters['status'] ?? '') === $k)>{{ $label }}</option>@endforeach
            </select>
        </div>
        <div class="col-6 col-md-2">
            <label class="form-label small" for="from">From</label>
            <input id="from" type="date" name="from" value="{{ $filters['from'] ?? '' }}" class="form-control form-control-sm">
        </div>
        <div class="col-6 col-md-2">
            <label class="form-label small" for="to">To</label>
            <input id="to" type="date" name="to" value="{{ $filters['to'] ?? '' }}" class="form-control form-control-sm">
        </div>
        <div class="col-6 col-md-2"><button class="btn btn-sm btn-primary w-100">Filter</button></div>
    </div>
</form>

<div class="erp-card">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0 table-stack">
            <thead class="table-light"><tr><th>Number</th><th>Date</th><th>Party</th><th>Reference</th><th>Status</th><th class="text-end">Taxable</th><th class="text-end">Total</th></tr></thead>
            <tbody>
            @forelse ($invoices as $inv)
                <tr class="{{ $inv->status() === 'cancelled' ? 'text-secondary text-decoration-line-through' : '' }}">
                    <td data-label="Number"><a href="{{ route('invoices.show', $inv) }}" class="fw-semibold">{{ $inv->displayNumber() }}</a></td>
                    <td data-label="Date">{{ $inv->date->format('d-m-Y') }}</td>
                    <td data-label="Party">{{ $inv->party_name }}</td>
                    <td data-label="Reference">{{ $inv->reference ?? '—' }}</td>
                    <td data-label="Status">@include('accounting.vouchers._status', ['status' => $inv->status()])</td>
                    <td data-label="Taxable" class="amount">{{ \App\Support\Money::format($inv->taxable_total) }}</td>
                    <td data-label="Total" class="amount fw-semibold">{{ \App\Support\Money::format($inv->grand_total) }}</td>
                </tr>
            @empty
                <tr><td colspan="7" class="text-center text-secondary py-4">Nothing here yet.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
<div class="mt-3">{{ $invoices->links() }}</div>
@endsection
