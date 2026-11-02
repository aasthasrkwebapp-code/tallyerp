@extends('layouts.erp')

@section('title', $ledger->exists ? 'Edit '.$labels['singular'] : 'New '.$labels['singular'])

@section('content')
<div class="page-header">
    <h1>{{ $ledger->exists ? 'Edit '.mb_strtolower($labels['singular']).': '.$ledger->name : 'New '.mb_strtolower($labels['singular']) }}</h1>
    <a href="{{ $ledger->exists ? route($labels['route'].'.show', $ledger) : route($labels['route'].'.index') }}" class="btn btn-outline-secondary">Back</a>
</div>

<form method="POST" action="{{ $ledger->exists ? route($labels['route'].'.update', $ledger) : route($labels['route'].'.store') }}" class="erp-card p-3 p-md-4" style="max-width: 1040px">
    @csrf
    @if ($ledger->exists) @method('PUT') @endif

    <div class="row g-3">
        <div class="col-12 col-md-6">
            <label for="name" class="form-label">Name <span class="text-danger">*</span></label>
            <input id="name" name="name" value="{{ old('name', $ledger->name) }}" class="form-control @error('name') is-invalid @enderror" required maxlength="150" autofocus>
            @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <div class="col-6 col-md-3">
            <label for="alias" class="form-label">Alias</label>
            <input id="alias" name="alias" value="{{ old('alias', $ledger->alias) }}" class="form-control" maxlength="150">
        </div>
        <div class="col-6 col-md-3">
            <label for="code" class="form-label">Code</label>
            <input id="code" name="code" value="{{ old('code', $ledger->code) }}" class="form-control @error('code') is-invalid @enderror" maxlength="50">
            @error('code')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <div class="col-12 col-md-6">
            <label for="account_group_id" class="form-label">Under group</label>
            <select id="account_group_id" name="account_group_id" class="form-select @error('account_group_id') is-invalid @enderror">
                @foreach ($groups as $g)
                    <option value="{{ $g->id }}" @selected((int) old('account_group_id', $ledger->account_group_id) === $g->id)>{{ $g->name }}</option>
                @endforeach
            </select>
            @error('account_group_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <div class="col-6 col-md-3">
            <label for="opening_balance" class="form-label">Opening balance</label>
            <input id="opening_balance" name="opening_balance" inputmode="decimal" value="{{ old('opening_balance', $ledger->opening_balance ?? '0.00') }}" class="form-control text-end @error('opening_balance') is-invalid @enderror">
            @error('opening_balance')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <div class="col-6 col-md-3">
            <label for="opening_balance_type" class="form-label">Dr / Cr</label>
            <select id="opening_balance_type" name="opening_balance_type" class="form-select">
                <option value="Dr" @selected(old('opening_balance_type', $ledger->opening_balance_type) === 'Dr')>Dr ({{ $kind === 'customer' ? 'receivable' : 'advance paid' }})</option>
                <option value="Cr" @selected(old('opening_balance_type', $ledger->opening_balance_type) === 'Cr')>Cr ({{ $kind === 'customer' ? 'advance received' : 'payable' }})</option>
            </select>
        </div>

        <fieldset class="col-12">
            <legend class="h6 fw-bold text-secondary mt-2">GST details</legend>
            <div class="row g-3">
                <div class="col-12 col-md-4">
                    <label for="gst_registration_type" class="form-label">Registration type</label>
                    <select id="gst_registration_type" name="gst_registration_type" class="form-select">
                        @foreach (config('erp.gst_registration_types') as $k => $label)
                            <option value="{{ $k }}" @selected(old('gst_registration_type', $party->gst_registration_type) === $k)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-6 col-md-4">
                    <label for="gstin" class="form-label">GSTIN</label>
                    <input id="gstin" name="gstin" value="{{ old('gstin', $party->gstin) }}" class="form-control text-uppercase font-monospace @error('gstin') is-invalid @enderror" maxlength="15" placeholder="27ABCDE1234F1Z5">
                    @error('gstin')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-6 col-md-4">
                    <label for="pan" class="form-label">PAN</label>
                    <input id="pan" name="pan" value="{{ old('pan', $party->pan) }}" class="form-control text-uppercase font-monospace @error('pan') is-invalid @enderror" maxlength="10">
                    @error('pan')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
            </div>
        </fieldset>

        <fieldset class="col-12">
            <legend class="h6 fw-bold text-secondary mt-2">Address and contact</legend>
            <div class="row g-3">
                <div class="col-12 col-md-6">
                    <label for="billing_address" class="form-label">Billing address</label>
                    <textarea id="billing_address" name="billing_address" rows="3" class="form-control">{{ old('billing_address', $party->billing_address) }}</textarea>
                </div>
                <div class="col-12 col-md-6">
                    <label for="shipping_address" class="form-label">Shipping address <small class="text-secondary">(if different)</small></label>
                    <textarea id="shipping_address" name="shipping_address" rows="3" class="form-control">{{ old('shipping_address', $party->shipping_address) }}</textarea>
                </div>
                <div class="col-6 col-md-3">
                    <label for="city" class="form-label">City</label>
                    <input id="city" name="city" value="{{ old('city', $party->city) }}" class="form-control" maxlength="100">
                </div>
                <div class="col-6 col-md-3">
                    <label for="state" class="form-label">State <span class="text-danger">*</span></label>
                    <select id="state" name="state" class="form-select @error('state') is-invalid @enderror">
                        <option value="">Select</option>
                        @foreach (config('india.states') as $code => $name)
                            <option value="{{ $name }}" @selected(old('state', $party->state) === $name)>{{ str_pad((string) $code, 2, '0', STR_PAD_LEFT) }} - {{ $name }}</option>
                        @endforeach
                    </select>
                    @error('state')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-6 col-md-3">
                    <label for="pincode" class="form-label">PIN code</label>
                    <input id="pincode" name="pincode" inputmode="numeric" value="{{ old('pincode', $party->pincode) }}" class="form-control @error('pincode') is-invalid @enderror" maxlength="6">
                    @error('pincode')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-6 col-md-3">
                    <label for="country" class="form-label">Country</label>
                    <input id="country" name="country" value="{{ old('country', $party->country ?? 'India') }}" class="form-control" maxlength="100">
                </div>
                <div class="col-12 col-md-4">
                    <label for="contact_person" class="form-label">Contact person</label>
                    <input id="contact_person" name="contact_person" value="{{ old('contact_person', $party->contact_person) }}" class="form-control" maxlength="150">
                </div>
                <div class="col-6 col-md-4">
                    <label for="phone" class="form-label">Phone</label>
                    <input id="phone" name="phone" type="tel" value="{{ old('phone', $party->phone) }}" class="form-control @error('phone') is-invalid @enderror" maxlength="30">
                    @error('phone')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-6 col-md-4">
                    <label for="email" class="form-label">Email</label>
                    <input id="email" name="email" type="email" value="{{ old('email', $party->email) }}" class="form-control @error('email') is-invalid @enderror" maxlength="255">
                    @error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
            </div>
        </fieldset>

        <fieldset class="col-12">
            <legend class="h6 fw-bold text-secondary mt-2">Credit control</legend>
            <div class="row g-3">
                <div class="col-12 col-md-4 d-flex align-items-end">
                    <div class="form-check mb-2">
                        <input type="hidden" name="maintain_bill_wise" value="0">
                        <input type="checkbox" class="form-check-input" id="maintain_bill_wise" name="maintain_bill_wise" value="1" @checked(old('maintain_bill_wise', $ledger->maintain_bill_wise))>
                        <label for="maintain_bill_wise" class="form-check-label">Maintain balances bill-by-bill</label>
                    </div>
                </div>
                <div class="col-6 col-md-4">
                    <label for="credit_period_days" class="form-label">Credit period (days)</label>
                    <input id="credit_period_days" type="number" min="0" max="3650" name="credit_period_days" value="{{ old('credit_period_days', $ledger->credit_period_days) }}" class="form-control @error('credit_period_days') is-invalid @enderror">
                    @error('credit_period_days')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-6 col-md-4">
                    <label for="credit_limit" class="form-label">Credit limit</label>
                    <input id="credit_limit" name="credit_limit" inputmode="decimal" value="{{ old('credit_limit', $ledger->credit_limit) }}" class="form-control text-end @error('credit_limit') is-invalid @enderror">
                    @error('credit_limit')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
            </div>
        </fieldset>

        <div class="col-12">
            <label for="notes" class="form-label">Notes</label>
            <textarea id="notes" name="notes" rows="2" class="form-control">{{ old('notes', $ledger->notes) }}</textarea>
        </div>
        <div class="col-12">
            <div class="form-check form-switch">
                <input type="hidden" name="is_active" value="0">
                <input type="checkbox" class="form-check-input" id="is_active" name="is_active" value="1" @checked(old('is_active', $ledger->is_active ?? true))>
                <label for="is_active" class="form-check-label">Active</label>
            </div>
        </div>
    </div>

    <button class="btn btn-primary mt-4" data-shortcut="s">Save {{ mb_strtolower($labels['singular']) }}</button>
</form>
@endsection

@push('scripts')
@php($stateCodes = collect(config('india.states'))->mapWithKeys(fn ($name, $code) => [str_pad((string) $code, 2, '0', STR_PAD_LEFT) => $name]))
<script>
(function () {
    // Fill the PAN and state from the GSTIN when they are empty.
    var gstin = document.getElementById('gstin');
    var states = @json($stateCodes);
    gstin.addEventListener('change', function () {
        var v = gstin.value.trim().toUpperCase();
        gstin.value = v;
        if (v.length !== 15) return;
        var pan = document.getElementById('pan');
        if (!pan.value) pan.value = v.substr(2, 10);
        var state = document.getElementById('state');
        if (!state.value && states[v.substr(0, 2)]) state.value = states[v.substr(0, 2)];
    });
})();
</script>
@endpush
