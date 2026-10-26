
@extends('layouts.erp')

@section('content')
<div class="container-fluid py-3">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
        <div>
            <h2 class="h4 mb-1">Create Company</h2>
            <p class="text-secondary mb-0">Set up your business accounting profile.</p>
        </div>
        <a href="{{ route('companies.index') }}" class="btn btn-outline-secondary">
            Back to Companies
        </a>
    </div>

    @if ($errors->any())
        <div class="alert alert-danger">
            Please correct the errors below.
            <ul class="mb-0 mt-2">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="POST" action="{{ route('companies.store') }}">
        @csrf

        <div class="card border-0 shadow-sm">
            <div class="card-body p-3 p-md-4">
                <div class="row g-3">
                    <div class="col-12 col-md-6">
                        <label class="form-label">Company Name *</label>
                        <input name="name" value="{{ old('name') }}"
                            class="form-control" required maxlength="255">
                    </div>

                    <div class="col-12 col-md-6">
                        <label class="form-label">Legal Name</label>
                        <input name="legal_name" value="{{ old('legal_name') }}"
                            class="form-control" maxlength="255">
                    </div>

                    <div class="col-12 col-md-6">
                        <label class="form-label">GSTIN</label>
                        <input name="gstin" value="{{ old('gstin') }}"
                            class="form-control text-uppercase" maxlength="15">
                    </div>

                    <div class="col-12 col-md-6">
                        <label class="form-label">PAN</label>
                        <input name="pan" value="{{ old('pan') }}"
                            class="form-control text-uppercase" maxlength="10">
                    </div>

                    <div class="col-12 col-md-6">
                        <label class="form-label">Email</label>
                        <input type="email" name="email" value="{{ old('email') }}"
                            class="form-control">
                    </div>

                    <div class="col-12 col-md-6">
                        <label class="form-label">Phone</label>
                        <input name="phone" value="{{ old('phone') }}"
                            class="form-control" maxlength="20">
                    </div>

                    <div class="col-12">
                        <label class="form-label">Business Address</label>
                        <textarea name="address" rows="3"
                            class="form-control">{{ old('address') }}</textarea>
                    </div>

                    <div class="col-12 col-md-6">
                        <label class="form-label">State</label>
                        <input name="state" value="{{ old('state') }}"
                            class="form-control">
                    </div>

                    <div class="col-12 col-md-6">
                        <label class="form-label">Financial Year *</label>
                        <select name="financial_year" class="form-select" required>
                            @foreach (['2026-27', '2025-26', '2027-28'] as $year)
                                <option value="{{ $year }}"
                                    @selected(old('financial_year', '2026-27') === $year)>
                                    {{ $year }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div class="col-12 col-md-6">
                        <label class="form-label">Currency</label>
                        <select name="currency" class="form-select" required>
                            <option value="INR">INR — Indian Rupee</option>
                        </select>
                    </div>
                </div>

                <div class="d-flex flex-wrap gap-2 mt-4">
                    <button type="submit" class="btn btn-primary">Save Company</button>
                    <a href="{{ route('companies.index') }}"
                        class="btn btn-light border">Cancel</a>
                </div>
            </div>
        </div>
    </form>
</div>
@endsection
