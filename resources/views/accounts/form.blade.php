@extends('layouts.erp')

@section('title', $account->exists ? 'Edit Ledger' : 'Create Ledger')

@section('content')
<div class="container-fluid py-3">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
        <div>
            <h2 class="h4 mb-1">{{ $account->exists ? 'Edit Ledger' : 'Create Ledger' }}</h2>
            <p class="text-secondary mb-0">{{ $company->name }}</p>
        </div>
        <a href="{{ route('companies.accounts.index', $company) }}" class="btn btn-outline-secondary">
            Back to Ledgers
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

    <form method="POST"
        action="{{ $account->exists
            ? route('companies.accounts.update', [$company, $account])
            : route('companies.accounts.store', $company) }}">
        @csrf
        @if ($account->exists)
            @method('PUT')
        @endif

        <div class="card border-0 shadow-sm">
            <div class="card-body p-3 p-md-4">
                <div class="row g-3">
                    <div class="col-12 col-md-6">
                        <label class="form-label" for="f-name">Name *</label>
                        <input id="f-name" name="name" value="{{ old('name', $account->name) }}"
                            class="form-control" required maxlength="255">
                    </div>

                    <div class="col-12 col-md-6">
                        <label class="form-label" for="f-code">Code</label>
                        <input id="f-code" name="code" value="{{ old('code', $account->code) }}"
                            class="form-control" maxlength="50">
                    </div>

                    <div class="col-12 col-md-6">
                        <label class="form-label" for="f-account-group-id">Under *</label>
                        <select id="f-account-group-id" name="account_group_id" class="form-select" required>
                            <option value="">Select group</option>
                            @foreach ($groups as $group)
                                <option value="{{ $group->id }}"
                                    @selected((string) old('account_group_id', $account->account_group_id) === (string) $group->id)>
                                    {{ $group->name }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div class="col-8 col-md-4">
                        <label class="form-label" for="f-opening-balance">Opening Balance</label>
                        <input id="f-opening-balance" type="number" step="0.01" min="0" name="opening_balance"
                            value="{{ old('opening_balance', $account->opening_balance ?? 0) }}"
                            class="form-control">
                    </div>

                    <div class="col-4 col-md-2">
                        <label class="form-label" for="f-opening-balance-type">Dr / Cr</label>
                        <select id="f-opening-balance-type" name="opening_balance_type" class="form-select">
                            @foreach (['dr' => 'Dr', 'cr' => 'Cr'] as $value => $label)
                                <option value="{{ $value }}"
                                    @selected(old('opening_balance_type', $account->opening_balance_type) === $value)>
                                    {{ $label }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    @if ($currencies->isNotEmpty())
                        <div class="col-6 col-md-3">
                            <label class="form-label" for="f-currency-code">Currency</label>
                            <select id="f-currency-code" name="currency_code" class="form-select">
                                <option value="">{{ $company->currency }} (base)</option>
                                @foreach ($currencies as $currency)
                                    <option value="{{ $currency->code }}" @selected(old('currency_code', $account->currency_code) === $currency->code)>{{ $currency->code }}{{ $currency->name ? ' - '.$currency->name : '' }}</option>
                                @endforeach
                            </select>
                            <div class="form-text">Opening balance stays in {{ $company->currency }}.</div>
                        </div>
                    @endif

                    <div class="col-12">
                        <h3 class="h6 mt-2 mb-0">GST details</h3>
                        <div class="form-text mt-0">For parties fill GSTIN/state; for sales or purchase ledgers fill HSN/SAC and rate.</div>
                    </div>

                    <div class="col-12 col-md-6">
                        <label class="form-label" for="f-gstin">GSTIN</label>
                        <input id="f-gstin" name="gstin" value="{{ old('gstin', $account->gstin) }}"
                            class="form-control text-uppercase" maxlength="15">
                    </div>

                    <div class="col-12 col-md-6">
                        <label class="form-label" for="f-state-code">State</label>
                        <select id="f-state-code" name="state_code" class="form-select">
                            <option value="">—</option>
                            @foreach (\App\Support\GstStates::STATES as $code => $name)
                                <option value="{{ $code }}" @selected(old('state_code', $account->state_code) === $code)>
                                    {{ $code }} - {{ $name }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div class="col-12 col-md-6">
                        <label class="form-label" for="f-address">Address</label>
                        <input id="f-address" name="address" value="{{ old('address', $account->address) }}" class="form-control" maxlength="500">
                    </div>

                    <div class="col-6 col-md-3">
                        <label class="form-label" for="f-city">City</label>
                        <input id="f-city" name="city" value="{{ old('city', $account->city) }}" class="form-control" maxlength="100">
                    </div>

                    <div class="col-6 col-md-3">
                        <label class="form-label" for="f-pincode">PIN code</label>
                        <input id="f-pincode" name="pincode" value="{{ old('pincode', $account->pincode) }}" class="form-control" maxlength="6" inputmode="numeric">
                    </div>

                    <div class="col-6 col-md-3">
                        <label class="form-label" for="f-hsn-sac">HSN / SAC</label>
                        <input id="f-hsn-sac" name="hsn_sac" value="{{ old('hsn_sac', $account->hsn_sac) }}"
                            class="form-control" maxlength="10" inputmode="numeric">
                    </div>

                    <div class="col-6 col-md-3">
                        <label class="form-label" for="f-gst-rate">GST Rate %</label>
                        <select id="f-gst-rate" name="gst_rate" class="form-select">
                            <option value="">—</option>
                            @foreach (\App\Http\Controllers\GstInvoiceController::RATES as $rate)
                                <option value="{{ $rate }}"
                                    @selected((string) old('gst_rate', $account->gst_rate === null ? '' : (float) $account->gst_rate) === (string) $rate)>
                                    {{ $rate }}%
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div class="col-12">
                        <input type="hidden" name="itc_blocked" value="0">
                        <div class="form-check">
                            <input type="checkbox" class="form-check-input" id="itc_blocked" name="itc_blocked" value="1"
                                @checked(old('itc_blocked', $account->itc_blocked))>
                            <label class="form-check-label" for="itc_blocked">GST credit not available on purchases to this ledger</label>
                            <div class="form-text mt-0">Blocked credit, e.g. motor cars, food, club fees or personal use. The GST is added to the cost instead.</div>
                        </div>
                    </div>

                    <div class="col-6 col-md-3">
                        <label class="form-label" for="f-pan">PAN</label>
                        <input id="f-pan" name="pan" value="{{ old('pan', $account->pan) }}" class="form-control text-uppercase" maxlength="10">
                    </div>

                    <div class="col-6 col-md-5">
                        <label class="form-label" for="f-tds-section">TDS section</label>
                        <select id="f-tds-section" name="tds_section" class="form-select">
                            <option value="">No TDS</option>
                            @foreach (\App\Support\Tds::SECTIONS as $key => [$section, $label, $rate])
                                <option value="{{ $key }}" @selected(old('tds_section', $account->tds_section) === $key)>{{ $section }} · {{ $label }} ({{ $rate }}%)</option>
                            @endforeach
                        </select>
                        <div class="form-text">For suppliers you deduct TDS from.</div>
                    </div>

                    <div class="col-12">
                        <input type="hidden" name="is_active" value="0">
                        <div class="form-check">
                            <input type="checkbox" class="form-check-input" id="is_active" name="is_active" value="1"
                                @checked(old('is_active', $account->is_active))>
                            <label class="form-check-label" for="is_active">Active</label>
                        </div>
                    </div>
                </div>
            </div>
            <div class="card-footer bg-white text-end">
                <button class="btn btn-primary">Save Ledger</button>
            </div>
        </div>
    </form>
</div>
@endsection
