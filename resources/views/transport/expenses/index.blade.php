@extends('layouts.erp')

@section('title', 'Transport Expenses')

@php($m = fn ($v) => \App\Support\Money::format($v))

@section('content')
<div class="page-header">
    <div>
        <h1>Trip &amp; vehicle expenses</h1>
        <p class="text-secondary mb-0">Fuel, tolls, driver allowances, repairs and other running costs. Each one posts a voucher.</p>
    </div>
    @can('transport.create')
        <a href="{{ route('transport-expenses.create') }}" class="btn btn-primary" data-shortcut="c"><i class="bi bi-plus-lg me-1"></i>New expense</a>
    @endcan
</div>

<form method="GET" class="erp-card p-3 mb-3">
    <div class="row g-2 align-items-end">
        <div class="col-6 col-md-3">
            <label class="form-label small" for="vehicle">Vehicle</label>
            <select id="vehicle" name="vehicle" class="form-select form-select-sm">
                <option value="">All</option>
                @foreach ($vehicles as $v)<option value="{{ $v->id }}" @selected((int) ($filters['vehicle'] ?? 0) === $v->id)>{{ $v->number }}</option>@endforeach
            </select>
        </div>
        <div class="col-6 col-md-3">
            <label class="form-label small" for="category">Type</label>
            <select id="category" name="category" class="form-select form-select-sm">
                <option value="">All</option>
                @foreach (config('erp.transport_expense_categories') as $k => $c)<option value="{{ $k }}" @selected(($filters['category'] ?? '') === $k)>{{ $c['label'] }}</option>@endforeach
            </select>
        </div>
        <div class="col-6 col-md-2"><label class="form-label small" for="from">From</label><input type="date" id="from" name="from" value="{{ $filters['from'] ?? '' }}" class="form-control form-control-sm"></div>
        <div class="col-6 col-md-2"><label class="form-label small" for="to">To</label><input type="date" id="to" name="to" value="{{ $filters['to'] ?? '' }}" class="form-control form-control-sm"></div>
        <div class="col-12 col-md-2"><button class="btn btn-sm btn-primary w-100">Filter</button></div>
    </div>
</form>

<div class="erp-card">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0 table-stack">
            <thead class="table-light"><tr><th>Date</th><th>Vehicle / trip</th><th>Expense</th><th>Paid from</th><th class="text-end">Amount</th><th class="text-end"></th></tr></thead>
            <tbody>
            @forelse ($expenses as $e)
                <tr class="{{ $e->status === 'cancelled' ? 'text-secondary' : '' }}">
                    <td data-label="Date" class="text-nowrap">{{ $e->date->format('d-m-Y') }}</td>
                    <td data-label="Vehicle / trip">{{ $e->vehicle->number }}@if ($e->trip) · <a href="{{ route('trips.show', $e->trip) }}">{{ $e->trip->number }}</a>@endif</td>
                    <td data-label="Expense">{{ $e->categoryLabel() }}@if ($e->quantity) · {{ \App\Support\Decimal::trim($e->quantity) }} L @endif @if ($e->description)<span class="small text-secondary">· {{ $e->description }}</span>@endif @if ($e->status === 'cancelled')<span class="badge text-bg-secondary">Cancelled</span>@endif</td>
                    <td data-label="Paid from">{{ $e->paidAccount->name }}@if ($e->voucher) <a href="{{ route('vouchers.show', $e->voucher) }}" class="small">{{ $e->voucher->voucher_number }}</a>@endif</td>
                    <td data-label="Amount" class="amount">{{ $m($e->amount) }}</td>
                    <td data-label="" class="text-end">
                        @if ($e->status === 'posted')
                            @can('transport.delete')
                                <form method="POST" action="{{ route('transport-expenses.cancel', $e) }}" class="d-inline-flex gap-1" data-confirm="Cancel this expense and its voucher?">
                                    @csrf
                                    <input name="reason" class="form-control form-control-sm" placeholder="Reason" required maxlength="255" style="width: 8rem" aria-label="Reason">
                                    <button class="btn btn-sm btn-outline-danger">Cancel</button>
                                </form>
                            @endcan
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="6" class="text-secondary text-center py-4">No expenses recorded.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
<div class="mt-3">{{ $expenses->links() }}</div>
@endsection
