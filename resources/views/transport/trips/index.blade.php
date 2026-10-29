@extends('layouts.erp')

@section('title', 'Trips')

@php($m = fn ($v) => \App\Support\Money::format($v))
@php($badge = ['open' => 'info', 'completed' => 'success', 'cancelled' => 'secondary'])

@section('content')
<div class="page-header">
    <div>
        <h1>Trips</h1>
        <p class="text-secondary mb-0">Each trip carries its freight, expenses and freight bill.</p>
    </div>
    <div class="d-flex flex-wrap gap-2">
        @if (auth()->user()->can('transport.edit') && auth()->user()->can('sales.create'))
            <a href="{{ route('trips.bill-many') }}" class="btn btn-outline-secondary"><i class="bi bi-receipt me-1"></i>Bill several trips</a>
        @endif
        @can('transport.create')
            <a href="{{ route('trips.create') }}" class="btn btn-primary" data-shortcut="c"><i class="bi bi-plus-lg me-1"></i>New trip</a>
        @endcan
    </div>
</div>

<form method="GET" class="erp-card p-3 mb-3">
    <div class="row g-2 align-items-end">
        <div class="col-12 col-md-3">
            <label class="form-label small" for="search">Search</label>
            <input id="search" name="search" value="{{ $filters['search'] ?? '' }}" class="form-control form-control-sm" placeholder="Trip, LR, place">
        </div>
        <div class="col-6 col-md-3">
            <label class="form-label small" for="vehicle">Vehicle</label>
            <select id="vehicle" name="vehicle" class="form-select form-select-sm">
                <option value="">All</option>
                @foreach ($vehicles as $v)<option value="{{ $v->id }}" @selected((int) ($filters['vehicle'] ?? 0) === $v->id)>{{ $v->number }}</option>@endforeach
            </select>
        </div>
        <div class="col-6 col-md-2">
            <label class="form-label small" for="status">Status</label>
            <select id="status" name="status" class="form-select form-select-sm">
                <option value="">All</option>
                @foreach (\App\Models\Trip::STATUSES as $k => $label)<option value="{{ $k }}" @selected(($filters['status'] ?? '') === $k)>{{ $label }}</option>@endforeach
            </select>
        </div>
        <div class="col-6 col-md-2">
            <label class="form-label small" for="billing">Billing</label>
            <select id="billing" name="billing" class="form-select form-select-sm">
                <option value="">All</option>
                <option value="unbilled" @selected(($filters['billing'] ?? '') === 'unbilled')>Completed, not billed</option>
                <option value="billed" @selected(($filters['billing'] ?? '') === 'billed')>Billed</option>
            </select>
        </div>
        <div class="col-6 col-md-2"><button class="btn btn-sm btn-primary w-100">Filter</button></div>
    </div>
</form>

<div class="erp-card">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0 table-stack">
            <thead class="table-light"><tr><th>Trip</th><th>Date</th><th>Vehicle</th><th>From – to</th><th>Customer</th><th class="text-end">Freight</th><th>Status</th></tr></thead>
            <tbody>
            @forelse ($trips as $t)
                <tr>
                    <td data-label="Trip"><a href="{{ route('trips.show', $t) }}" class="fw-semibold">{{ $t->number }}</a>@if ($t->lr_number) <span class="text-secondary small">LR {{ $t->lr_number }}</span>@endif</td>
                    <td data-label="Date" class="text-nowrap">{{ $t->start_date->format('d-m-Y') }}</td>
                    <td data-label="Vehicle">{{ $t->vehicle->number }}</td>
                    <td data-label="From – to">{{ $t->origin }} – {{ $t->destination }}</td>
                    <td data-label="Customer">{{ $t->party?->name ?? '—' }}</td>
                    <td data-label="Freight" class="amount">{{ $m($t->freight_amount) }}</td>
                    <td data-label="Status">
                        <span class="badge text-bg-{{ $badge[$t->status] }}">{{ \App\Models\Trip::STATUSES[$t->status] }}</span>
                        @if ($t->postedInvoice())<span class="badge text-bg-light border">Billed</span>@elseif ($t->status === 'completed')<span class="badge text-bg-warning">Not billed</span>@endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="7" class="text-secondary text-center py-4">No trips yet. Add <a href="{{ route('transport-masters.index', 'vehicles') }}">vehicles</a> first, then create a trip.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
<div class="mt-3">{{ $trips->links() }}</div>
@endsection
