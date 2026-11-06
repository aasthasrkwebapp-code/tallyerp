@extends('layouts.erp')

@section('title', 'Loan from '.$loan->lender)

@section('content')
@php
    $interestDue = $loan->interestOn($outstanding);
    $next = $outstanding > 0 ? min($outstanding + $interestDue, (float) $loan->emi) : 0;
@endphp
<div class="container-fluid py-3">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
        <div>
            <h2 class="h4 mb-1">Loan from {{ $loan->lender }}</h2>
            <p class="text-secondary mb-0">₹ {{ inr($loan->principal) }} at {{ rtrim(rtrim($loan->annual_rate, '0'), '.') }}% for {{ $loan->tenure_months }} months · EMI ₹ {{ inr($loan->emi) }}</p>
        </div>
        <div class="d-flex gap-2">
            <a href="{{ route('companies.loans.index', $company) }}" class="btn btn-outline-secondary">Back</a>
            <form method="POST" action="{{ route('companies.loans.destroy', [$company, $loan]) }}" onsubmit="return confirm('Delete this loan?')">@csrf @method('DELETE')<button class="btn btn-outline-danger">Delete</button></form>
        </div>
    </div>

    @if (session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
    @if (session('error'))<div class="alert alert-danger">{{ session('error') }}</div>@endif
    @if ($errors->any())<div class="alert alert-danger"><ul class="mb-0">@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>@endif

    <div class="row g-3 mb-4">
        <div class="col-md-4"><div class="card border-0 shadow-sm card-body"><div class="text-secondary small">Outstanding</div><div class="h4 mb-0">₹ {{ inr($outstanding) }}</div></div></div>
        <div class="col-md-8">
            @if ($outstanding > 0)
            <form method="POST" action="{{ route('companies.loans.pay', [$company, $loan]) }}" class="card border-0 shadow-sm card-body">
                @csrf
                <div class="fw-semibold mb-2">Pay EMI</div>
                <div class="row g-2 align-items-end">
                    <div class="col-sm-3"><label class="form-label small">Date</label><input type="date" name="date" class="form-control" value="{{ old('date', now()->toDateString()) }}" required></div>
                    <div class="col-sm-3"><label class="form-label small">Paid from</label><select name="paid_from" class="form-select" required>@foreach ($banks as $b)<option value="{{ $b->id }}">{{ $b->name }}</option>@endforeach</select></div>
                    <div class="col-sm-2"><label class="form-label small">Amount</label><input type="number" step="0.01" name="amount" class="form-control" value="{{ old('amount', number_format($next, 2, '.', '')) }}" required></div>
                    <div class="col-sm-2"><label class="form-label small">of which interest</label><input type="number" step="0.01" name="interest" class="form-control" value="{{ old('interest', number_format($interestDue, 2, '.', '')) }}" required></div>
                    <div class="col-sm-2"><button class="btn btn-primary w-100">Record</button></div>
                </div>
            </form>
            @endif
        </div>
    </div>

    <div class="row g-4">
        <div class="col-lg-7">
            <h3 class="h6">Repayment schedule</h3>
            <div class="card border-0 shadow-sm"><div class="table-responsive" style="max-height: 480px">
                <table class="table table-sm mb-0">
                    <thead class="table-light sticky-top"><tr><th>#</th><th>Due</th><th class="text-end">EMI</th><th class="text-end">Interest</th><th class="text-end">Principal</th><th class="text-end">Balance</th></tr></thead>
                    <tbody>
                        @foreach ($loan->schedule() as $row)
                            <tr><td>{{ $row['n'] }}</td><td>{{ $row['date']->format('d M Y') }}</td><td class="text-end">{{ inr($row['emi']) }}</td><td class="text-end">{{ inr($row['interest']) }}</td><td class="text-end">{{ inr($row['principal']) }}</td><td class="text-end">{{ inr($row['balance']) }}</td></tr>
                        @endforeach
                    </tbody>
                </table>
            </div></div>
        </div>
        <div class="col-lg-5">
            <h3 class="h6">Repayments made</h3>
            <div class="card border-0 shadow-sm"><div class="table-responsive">
                <table class="table table-sm mb-0">
                    <thead class="table-light"><tr><th>Date</th><th>Voucher</th><th class="text-end">Principal</th></tr></thead>
                    <tbody>
                        @forelse ($payments as $e)
                            <tr><td>{{ $e->voucher->date->format('d M Y') }}</td><td>{{ ucfirst($e->voucher->type) }} #{{ $e->voucher->number }}</td><td class="text-end">{{ inr($e->debit) }}</td></tr>
                        @empty
                            <tr><td colspan="3" class="text-secondary text-center py-3">None yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div></div>
        </div>
    </div>
</div>
@endsection
