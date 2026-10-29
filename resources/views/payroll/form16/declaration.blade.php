@extends('layouts.erp')

@section('title', 'Tax declaration '.$employee->name)

@section('content')
<div class="page-header">
    <div>
        <h1>Tax declaration · {{ $employee->name }}</h1>
        <p class="text-secondary mb-0">FY {{ $year->name }}. Amounts for the whole year, in rupees. Exemptions and Chapter VI-A deductions count only under the old regime.</p>
    </div>
</div>

<form method="POST" action="{{ route('form16.declaration.update', [$employee, 'year' => $year->id]) }}" class="erp-card p-3" style="max-width: 48rem">
    @csrf @method('PUT')
    <fieldset class="mb-3">
        <legend class="form-label">Tax regime</legend>
        @foreach (\App\Models\TaxDeclaration::REGIMES as $key => $label)
            <div class="form-check">
                <input class="form-check-input" type="radio" name="regime" id="regime-{{ $key }}" value="{{ $key }}" @checked(old('regime', $declaration->regime) === $key)>
                <label class="form-check-label" for="regime-{{ $key }}">{{ $label }}</label>
            </div>
        @endforeach
        @error('regime')<div class="small text-danger">{{ $message }}</div>@enderror
    </fieldset>
    <div class="row g-3">
        @foreach (\App\Models\TaxDeclaration::AMOUNTS as $field => $label)
            <div class="col-12 col-md-6">
                <label for="{{ $field }}" class="form-label">{{ $label }}@if (in_array($field, \App\Models\TaxDeclaration::OLD_REGIME_ONLY, true)) <span class="badge text-bg-light">old regime</span>@endif</label>
                <input id="{{ $field }}" name="{{ $field }}" inputmode="decimal" class="form-control text-end @error($field) is-invalid @enderror" value="{{ old($field, (float) $declaration->{$field} ? $declaration->{$field} : '') }}" placeholder="0">
                @error($field)<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
        @endforeach
    </div>
    <p class="small text-secondary mt-3 mb-2">Limits applied: 80C up to ₹{{ number_format(config('erp.income_tax.limits.section_80c')) }}, 80CCD(1B) up to ₹{{ number_format(config('erp.income_tax.limits.section_80ccd_1b')) }}, home loan interest up to ₹{{ number_format(config('erp.income_tax.limits.home_loan_interest')) }}. Keep the employee's proofs; the amounts are not checked against them.</p>
    <div class="d-flex gap-2">
        <button class="btn btn-primary">Save declaration</button>
        <a href="{{ route('form16.show', [$employee, 'year' => $year->id]) }}" class="btn btn-outline-secondary">Cancel</a>
    </div>
</form>
@endsection
