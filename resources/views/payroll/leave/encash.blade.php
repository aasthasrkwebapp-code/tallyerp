@extends('layouts.erp')

@section('title', 'Encash Leave')

@php($fmt = fn ($v) => $v === null ? '—' : \App\Support\Decimal::trim(number_format($v, 1, '.', '')))

@section('content')
<div class="page-header">
    <div>
        <h1>Encash leave</h1>
        <p class="text-secondary mb-0">Pay unused leave by a payment voucher (salaries and wages to cash or bank).</p>
    </div>
    <a href="{{ route('leave-encashments.index') }}" class="btn btn-outline-secondary">Back</a>
</div>

<form method="GET" class="erp-card p-3 mb-3" style="max-width: 820px">
    <div class="row g-2 align-items-end">
        <div class="col-12 col-sm-6">
            <label for="q-employee" class="form-label">Employee</label>
            <select id="q-employee" name="employee_id" class="form-select" onchange="this.form.submit()">
                <option value="">Choose…</option>
                @foreach ($employees as $e)<option value="{{ $e->id }}" @selected($employee?->id === $e->id)>{{ $e->name }} ({{ $e->code }})</option>@endforeach
            </select>
        </div>
        <div class="col-8 col-sm-4">
            <label for="q-date" class="form-label">Date</label>
            <input type="date" id="q-date" name="date" value="{{ $date }}" class="form-control">
        </div>
        <div class="col-4 col-sm-2"><button class="btn btn-outline-primary w-100">Show</button></div>
    </div>
</form>

@if ($employee)
    <div class="erp-card mb-3" style="max-width: 820px">
        <div class="erp-card-header">Leave balance of {{ $employee->name }} on {{ \Illuminate\Support\Carbon::parse($date)->format('d M Y') }}</div>
        <table class="table table-sm mb-0">
            <tbody>
            @forelse ($types as $t)
                <tr><td>{{ $t->name }}</td><td class="text-end fw-semibold">{{ $fmt($balances[$t->id] ?? null) }} days</td></tr>
            @empty
                <tr><td class="text-secondary">No paid leave type has a yearly allowance.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>

    @can('payroll.create')
    <form method="POST" action="{{ route('leave-encashments.store') }}" class="erp-card p-3" style="max-width: 820px" data-confirm="Pay this leave encashment?">
        @csrf
        <input type="hidden" name="employee_id" value="{{ $employee->id }}">
        <div class="row g-3">
            <div class="col-12 col-md-4">
                <label for="leave_type_id" class="form-label">Leave type</label>
                <select id="leave_type_id" name="leave_type_id" class="form-select @error('leave_type_id') is-invalid @enderror">
                    @foreach ($types as $t)<option value="{{ $t->id }}" @selected((int) old('leave_type_id') === $t->id)>{{ $t->name }}</option>@endforeach
                </select>
                @error('leave_type_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="col-6 col-md-4">
                <label for="date" class="form-label">Date paid</label>
                <input type="date" id="date" name="date" value="{{ old('date', $date) }}" class="form-control @error('date') is-invalid @enderror" required>
                @error('date')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="col-6 col-md-4">
                <label for="days" class="form-label">Days</label>
                <input id="days" name="days" value="{{ old('days') }}" inputmode="decimal" class="form-control text-end @error('days') is-invalid @enderror" required>
                @error('days')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="col-6 col-md-4">
                <label for="rate" class="form-label">Rate per day</label>
                <input id="rate" name="rate" value="{{ old('rate', $rate ? \App\Support\Money::fromPaise($rate) : '') }}" inputmode="decimal" class="form-control text-end @error('rate') is-invalid @enderror" required>
                <div class="form-text">Suggested: monthly basic ÷ 30.</div>
                @error('rate')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="col-6 col-md-4">
                <label for="paid_account_id" class="form-label">Paid from</label>
                <select id="paid_account_id" name="paid_account_id" class="form-select @error('paid_account_id') is-invalid @enderror">
                    @foreach ($paidFrom as $a)<option value="{{ $a->id }}" @selected((int) old('paid_account_id') === $a->id)>{{ $a->name }}</option>@endforeach
                </select>
                @error('paid_account_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="col-12 col-md-4">
                <label for="narration" class="form-label">Note</label>
                <input id="narration" name="narration" value="{{ old('narration') }}" maxlength="255" class="form-control">
            </div>
        </div>
        <button class="btn btn-primary mt-3">Pay encashment</button>
    </form>
    @endcan
@endif
@endsection
