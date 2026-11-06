@extends('layouts.erp')

@section('title', 'New Loan')

@section('content')
<div class="container py-3" style="max-width: 720px">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2 class="h4 mb-0">New Loan</h2>
        <a href="{{ route('companies.loans.index', $company) }}" class="btn btn-outline-secondary">Back</a>
    </div>

    @if ($errors->any())
        <div class="alert alert-danger"><ul class="mb-0">@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>
    @endif

    <form method="POST" action="{{ route('companies.loans.store', $company) }}" class="card border-0 shadow-sm card-body">
        @csrf
        <div class="row g-3">
            <div class="col-md-8"><label class="form-label">Lender (bank / person)</label><input name="lender" class="form-control" value="{{ old('lender') }}" required></div>
            <div class="col-md-4"><label class="form-label">Type</label>
                <select name="secured" class="form-select">
                    <option value="1" @selected(old('secured', '1') === '1')>Secured</option>
                    <option value="0" @selected(old('secured') === '0')>Unsecured</option>
                </select>
            </div>
            <div class="col-md-4"><label class="form-label">Amount (₹)</label><input name="principal" type="number" step="0.01" min="0.01" class="form-control" value="{{ old('principal') }}" required></div>
            <div class="col-md-4"><label class="form-label">Interest % a year</label><input name="annual_rate" type="number" step="0.001" min="0" class="form-control" value="{{ old('annual_rate') }}" required></div>
            <div class="col-md-4"><label class="form-label">Tenure (months)</label><input name="tenure_months" type="number" min="1" class="form-control" value="{{ old('tenure_months') }}" required></div>
            <div class="col-md-6"><label class="form-label">Date taken</label><input name="start_date" type="date" class="form-control" value="{{ old('start_date', now()->toDateString()) }}" required></div>
            <div class="col-md-6"><label class="form-label">Received in</label>
                <select name="received_in" class="form-select">
                    <option value="">— taken in an earlier year —</option>
                    @foreach ($banks as $b)<option value="{{ $b->id }}" @selected(old('received_in') == $b->id)>{{ $b->name }}</option>@endforeach
                </select>
                <div class="form-text">A loan from an earlier year becomes the ledger's opening balance.</div>
            </div>
        </div>
        <div class="mt-4"><button class="btn btn-primary">Save Loan</button></div>
    </form>
</div>
@endsection
