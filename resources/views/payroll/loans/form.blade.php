@extends('layouts.erp')

@section('title', 'New Loan or Advance')

@section('content')
<div class="page-header">
    <div>
        <h1>New loan or advance</h1>
        <p class="text-secondary mb-0">Saving posts a payment voucher. Payroll deducts the instalment each month from the month you choose until it is repaid.</p>
    </div>
    <a href="{{ route('employee-loans.index') }}" class="btn btn-outline-secondary">Back</a>
</div>

<form method="POST" action="{{ route('employee-loans.store') }}" class="erp-card p-3" style="max-width: 760px">
    @csrf
    <div class="row g-3">
        <div class="col-12 col-md-6">
            <label for="employee_id" class="form-label">Employee <span class="text-danger">*</span></label>
            <select id="employee_id" name="employee_id" class="form-select @error('employee_id') is-invalid @enderror" required>
                <option value="">Select</option>
                @foreach ($employees as $e)<option value="{{ $e->id }}" @selected((int) old('employee_id', $employeeId) === $e->id)>{{ $e->name }} ({{ $e->code }})</option>@endforeach
            </select>
            @error('employee_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <div class="col-6 col-md-3">
            <label for="kind" class="form-label">Type</label>
            <select id="kind" name="kind" class="form-select @error('kind') is-invalid @enderror">
                @foreach (\App\Models\EmployeeLoan::KINDS as $k => $label)<option value="{{ $k }}" @selected(old('kind', 'advance') === $k)>{{ $label }}</option>@endforeach
            </select>
            @error('kind')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <div class="col-6 col-md-3">
            <label for="date" class="form-label">Date paid</label>
            <input type="date" id="date" name="date" value="{{ old('date', now()->toDateString()) }}" class="form-control @error('date') is-invalid @enderror" required>
            @error('date')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <div class="col-6 col-md-4">
            <label for="amount" class="form-label">Amount <span class="text-danger">*</span></label>
            <input id="amount" name="amount" value="{{ old('amount') }}" class="form-control text-end @error('amount') is-invalid @enderror" inputmode="decimal" required>
            @error('amount')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <div class="col-6 col-md-4">
            <label for="installment" class="form-label">Monthly instalment <span class="text-danger">*</span></label>
            <input id="installment" name="installment" value="{{ old('installment') }}" class="form-control text-end @error('installment') is-invalid @enderror" inputmode="decimal" required>
            @error('installment')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <div class="col-12 col-md-4">
            <label for="recover_from" class="form-label">Recover from payroll of</label>
            <input type="month" id="recover_from" name="recover_from" value="{{ old('recover_from', now()->format('Y-m')) }}" class="form-control @error('recover_from') is-invalid @enderror" required>
            @error('recover_from')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <div class="col-12 col-md-6">
            <label for="paid_account_id" class="form-label">Paid from <span class="text-danger">*</span></label>
            <select id="paid_account_id" name="paid_account_id" class="form-select @error('paid_account_id') is-invalid @enderror" required>
                <option value="">Select cash or bank</option>
                @foreach ($paidFrom as $a)<option value="{{ $a->id }}" @selected((int) old('paid_account_id') === $a->id)>{{ $a->name }}</option>@endforeach
            </select>
            @error('paid_account_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <div class="col-12 col-md-6">
            <label for="account_id" class="form-label">Loan ledger</label>
            <select id="account_id" name="account_id" class="form-select @error('account_id') is-invalid @enderror">
                @foreach ($loanLedgers as $a)<option value="{{ $a->id }}" @selected((int) old('account_id', $defaultLedger) === $a->id)>{{ $a->name }}</option>@endforeach
            </select>
            @error('account_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <div class="col-12">
            <label for="description" class="form-label">Purpose</label>
            <input id="description" name="description" value="{{ old('description') }}" class="form-control @error('description') is-invalid @enderror" maxlength="255">
            @error('description')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
    </div>
    <button class="btn btn-primary mt-3">Save and post payment</button>
</form>
@endsection
