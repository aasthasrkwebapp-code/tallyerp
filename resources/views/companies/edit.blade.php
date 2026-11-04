@extends('layouts.erp')

@section('content')
<div class="container-fluid py-3">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
        <div>
            <h2 class="h4 mb-1">Edit Company</h2>
            <p class="text-secondary mb-0">Update your business accounting profile.</p>
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

    <form method="POST" action="{{ route('companies.update', $company) }}">
        @csrf
        @method('PUT')

        <div class="card border-0 shadow-sm">
            <div class="card-body p-3 p-md-4">
                <div class="row g-3">
                    <div class="col-12 col-md-6">
                        <label class="form-label" for="f-name">Company Name *</label>
                        <input id="f-name" name="name" value="{{ old('name', $company->name) }}"
                            class="form-control" required maxlength="255">
                    </div>

                    <div class="col-12 col-md-6">
                        <label class="form-label" for="f-legal-name">Legal Name</label>
                        <input id="f-legal-name" name="legal_name" value="{{ old('legal_name', $company->legal_name) }}"
                            class="form-control" maxlength="255">
                    </div>

                    <div class="col-12 col-md-6">
                        <label class="form-label" for="f-gstin">GSTIN</label>
                        <input id="f-gstin" name="gstin" value="{{ old('gstin', $company->gstin) }}"
                            class="form-control text-uppercase" maxlength="15">
                    </div>

                    <div class="col-12 col-md-6">
                        <label class="form-label" for="f-pan">PAN</label>
                        <input id="f-pan" name="pan" value="{{ old('pan', $company->pan) }}"
                            class="form-control text-uppercase" maxlength="10">
                    </div>

                    <div class="col-12 col-md-6">
                        <label class="form-label" for="f-email">Email</label>
                        <input id="f-email" type="email" name="email" value="{{ old('email', $company->email) }}"
                            class="form-control">
                    </div>

                    <div class="col-12 col-md-6">
                        <label class="form-label" for="f-phone">Phone</label>
                        <input id="f-phone" name="phone" value="{{ old('phone', $company->phone) }}"
                            class="form-control" maxlength="20">
                    </div>

                    <div class="col-12">
                        <label class="form-label" for="f-address">Business Address</label>
                        <textarea id="f-address" name="address" rows="3"
                            class="form-control">{{ old('address', $company->address) }}</textarea>
                    </div>

                    <div class="col-12 col-md-6">
                        <label class="form-label" for="f-state">State</label>
                        <input id="f-state" name="state" value="{{ old('state', $company->state) }}"
                            class="form-control">
                    </div>

                    <div class="col-6 col-md-3">
                        <label class="form-label" for="f-city">City</label>
                        <input id="f-city" name="city" value="{{ old('city', $company->city) }}" class="form-control" maxlength="100">
                    </div>

                    <div class="col-6 col-md-3">
                        <label class="form-label" for="f-pincode">PIN code</label>
                        <input id="f-pincode" name="pincode" value="{{ old('pincode', $company->pincode) }}" class="form-control" maxlength="6" inputmode="numeric">
                    </div>

                    <div class="col-12 col-md-6">
                        <label class="form-label" for="f-financial-year">Financial Year *</label>
                        <select id="f-financial-year" name="financial_year" class="form-select" required>
                            @foreach (['2026-27', '2025-26', '2027-28'] as $year)
                                <option value="{{ $year }}"
                                    @selected(old('financial_year', $company->financial_year) === $year)>
                                    {{ $year }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div class="col-12 col-md-6">
                        <label class="form-label" for="f-currency">Currency</label>
                        <select id="f-currency" name="currency" class="form-select" required>
                            <option value="INR" @selected(old('currency', $company->currency) === 'INR')>
                                INR — Indian Rupee
                            </option>
                        </select>
                    </div>

                    <div class="col-12"><h3 class="h6 mt-3 mb-0">Invoice printing</h3></div>
                    <div class="col-12 col-md-4">
                        <label class="form-label" for="f-invoice-template">Invoice design</label>
                        <select id="f-invoice-template" name="invoice_template" class="form-select">
                            @foreach (\App\Models\Company::INVOICE_TEMPLATES as $value => $label)
                                <option value="{{ $value }}" @selected(old('invoice_template', $company->invoice_template) === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-12 col-md-8">
                        <label class="form-label" for="f-upi-id">UPI ID (for the pay-by-QR code on invoices)</label>
                        <input id="f-upi-id" name="upi_id" value="{{ old('upi_id', $company->upi_id) }}" class="form-control" maxlength="100" placeholder="business@okhdfcbank">
                    </div>
                    <div class="col-12 col-md-4">
                        <label class="form-label" for="f-bank-name">Bank name</label>
                        <input id="f-bank-name" name="bank_name" value="{{ old('bank_name', $company->bank_name) }}" class="form-control" maxlength="255">
                    </div>
                    <div class="col-6 col-md-4">
                        <label class="form-label" for="f-bank-account-no">Account number</label>
                        <input id="f-bank-account-no" name="bank_account_no" value="{{ old('bank_account_no', $company->bank_account_no) }}" class="form-control" maxlength="34">
                    </div>
                    <div class="col-6 col-md-4">
                        <label class="form-label" for="f-bank-ifsc">IFSC</label>
                        <input id="f-bank-ifsc" name="bank_ifsc" value="{{ old('bank_ifsc', $company->bank_ifsc) }}" class="form-control text-uppercase" maxlength="11">
                    </div>
                    <div class="col-12">
                        <label class="form-label" for="f-invoice-terms">Terms &amp; conditions</label>
                        <textarea id="f-invoice-terms" name="invoice_terms" rows="3" maxlength="2000" class="form-control" placeholder="Goods once sold will not be taken back. Subject to Pune jurisdiction.">{{ old('invoice_terms', $company->invoice_terms) }}</textarea>
                    </div>
                </div>

                <div class="d-flex flex-wrap gap-2 mt-4">
                    <button type="submit" class="btn btn-primary">Update Company</button>
                    <a href="{{ route('companies.index') }}"
                        class="btn btn-light border">Cancel</a>
                </div>
            </div>
        </div>
    </form>
</div>
@endsection
