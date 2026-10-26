@extends('layouts.erp')

@section('title', $ledger->exists ? 'Edit Ledger' : 'New Ledger')

@section('content')
<div class="page-header">
    <h1>{{ $ledger->exists ? 'Edit ledger: '.$ledger->name : 'New ledger' }}</h1>
    <a href="{{ route('ledgers.index') }}" class="btn btn-outline-secondary">Back</a>
</div>

<form method="POST" action="{{ $ledger->exists ? route('ledgers.update', $ledger) : route('ledgers.store') }}" class="erp-card p-3 p-md-4" style="max-width: 960px">
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
            <label for="account_group_id" class="form-label">Under group <span class="text-danger">*</span></label>
            <select id="account_group_id" name="account_group_id" class="form-select @error('account_group_id') is-invalid @enderror" required @disabled($ledger->is_system)>
                <option value="">Select group</option>
                @foreach ($groups as $g)
                    <option value="{{ $g->id }}" data-key="{{ $g->system_key }}" data-nature="{{ $g->nature }}"
                        @selected((int) old('account_group_id', $ledger->account_group_id) === $g->id)>{{ str_repeat('— ', $g->depth) }}{{ $g->name }}</option>
                @endforeach
            </select>
            @if ($ledger->is_system)<input type="hidden" name="account_group_id" value="{{ $ledger->account_group_id }}">@endif
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
                <option value="Dr" @selected(old('opening_balance_type', $ledger->opening_balance_type) === 'Dr')>Dr (debit)</option>
                <option value="Cr" @selected(old('opening_balance_type', $ledger->opening_balance_type) === 'Cr')>Cr (credit)</option>
            </select>
        </div>

        <fieldset class="col-12 ledger-section" data-show-for="party">
            <legend class="h6 fw-bold text-secondary mt-2">Credit control</legend>
            <div class="row g-3">
                <div class="col-12 col-md-4 d-flex align-items-end">
                    <div class="form-check mb-2">
                        <input type="checkbox" class="form-check-input" id="maintain_bill_wise" name="maintain_bill_wise" value="1" @checked(old('maintain_bill_wise', $ledger->maintain_bill_wise ?? true))>
                        <label for="maintain_bill_wise" class="form-check-label">Maintain balances bill-by-bill</label>
                    </div>
                </div>
                <div class="col-6 col-md-4">
                    <label for="credit_period_days" class="form-label">Credit period (days)</label>
                    <input id="credit_period_days" type="number" min="0" name="credit_period_days" value="{{ old('credit_period_days', $ledger->credit_period_days) }}" class="form-control">
                </div>
                <div class="col-6 col-md-4">
                    <label for="credit_limit" class="form-label">Credit limit</label>
                    <input id="credit_limit" name="credit_limit" inputmode="decimal" value="{{ old('credit_limit', $ledger->credit_limit) }}" class="form-control text-end">
                </div>
            </div>
        </fieldset>

        <fieldset class="col-12 ledger-section" data-show-for="bank">
            <legend class="h6 fw-bold text-secondary mt-2">Bank details</legend>
            <div class="row g-3">
                <div class="col-12 col-md-6">
                    <label for="bank_name" class="form-label">Bank name</label>
                    <input id="bank_name" name="bank_name" value="{{ old('bank_name', $ledger->bank_name) }}" class="form-control">
                </div>
                <div class="col-12 col-md-6">
                    <label for="bank_account_number" class="form-label">Account number</label>
                    <input id="bank_account_number" name="bank_account_number" value="{{ old('bank_account_number', $ledger->bank_account_number) }}" class="form-control @error('bank_account_number') is-invalid @enderror">
                    @error('bank_account_number')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-6 col-md-6">
                    <label for="ifsc" class="form-label">IFSC</label>
                    <input id="ifsc" name="ifsc" value="{{ old('ifsc', $ledger->ifsc) }}" class="form-control text-uppercase @error('ifsc') is-invalid @enderror" maxlength="11">
                    @error('ifsc')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-6 col-md-6">
                    <label for="bank_branch" class="form-label">Branch</label>
                    <input id="bank_branch" name="bank_branch" value="{{ old('bank_branch', $ledger->bank_branch) }}" class="form-control">
                </div>
            </div>
        </fieldset>

        <fieldset class="col-12 ledger-section" data-show-for="tax">
            <legend class="h6 fw-bold text-secondary mt-2">Tax details</legend>
            <div class="row g-3">
                <div class="col-6 col-md-4">
                    <label for="tax_type" class="form-label">Type of duty / tax</label>
                    <select id="tax_type" name="tax_type" class="form-select">
                        <option value="">—</option>
                        @foreach (\App\Models\Account::TAX_TYPES as $k => $label)<option value="{{ $k }}" @selected(old('tax_type', $ledger->tax_type) === $k)>{{ $label }}</option>@endforeach
                    </select>
                </div>
                <div class="col-6 col-md-4">
                    <label for="tax_rate" class="form-label">Rate (%)</label>
                    <input id="tax_rate" name="tax_rate" inputmode="decimal" value="{{ old('tax_rate', $ledger->tax_rate) }}" class="form-control text-end">
                </div>
            </div>
        </fieldset>

        <fieldset class="col-12 ledger-section" data-show-for="income expense">
            <legend class="h6 fw-bold text-secondary mt-2">GST details (for sales / purchase / service ledgers)</legend>
            <div class="row g-3">
                <div class="col-6 col-md-4">
                    <label for="hsn_sac" class="form-label">HSN / SAC</label>
                    <input id="hsn_sac" name="hsn_sac" value="{{ old('hsn_sac', $ledger->hsn_sac) }}" class="form-control @error('hsn_sac') is-invalid @enderror" maxlength="8">
                    @error('hsn_sac')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-6 col-md-4">
                    <label for="gst_rate" class="form-label">GST rate (%)</label>
                    <select id="gst_rate" name="gst_rate" class="form-select">
                        <option value="">Not applicable</option>
                        @foreach (['0', '0.1', '0.25', '1', '1.5', '3', '5', '6', '7.5', '12', '18', '28', '40'] as $rate)
                            <option value="{{ $rate }}" @selected((string) old('gst_rate', $ledger->gst_rate !== null ? rtrim(rtrim($ledger->gst_rate, '0'), '.') : '') === $rate)>{{ $rate }}%</option>
                        @endforeach
                    </select>
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
                <input type="checkbox" class="form-check-input" id="is_active" name="is_active" value="1" @checked(old('is_active', $ledger->is_active ?? true)) @disabled($ledger->is_system)>
                <label for="is_active" class="form-check-label">Active</label>
            </div>
        </div>
    </div>

    <button class="btn btn-primary mt-4" data-shortcut="s">Save ledger</button>
</form>
@endsection

@push('scripts')
<script>
(function () {
    // Show only the sections relevant to the chosen group.
    var select = document.getElementById('account_group_id');
    var parents = @json($groups->mapWithKeys(fn ($g) => [$g->id => ['parent' => $g->parent_id, 'key' => $g->system_key, 'nature' => $g->nature]]));
    function kind(id) {
        var tags = [];
        var node = parents[id];
        if (!node) return tags;
        tags.push(node.nature);
        while (node) {
            if (node.key === 'sundry_debtors' || node.key === 'sundry_creditors') tags.push('party');
            if (node.key === 'bank_accounts' || node.key === 'bank_od') tags.push('bank');
            if (node.key === 'duties_taxes') tags.push('tax');
            node = parents[node.parent];
        }
        return tags;
    }
    function update() {
        var tags = kind(select.value);
        document.querySelectorAll('.ledger-section').forEach(function (el) {
            var show = el.dataset.showFor.split(' ').some(function (t) { return tags.indexOf(t) !== -1; });
            el.hidden = !show;
        });
    }
    select.addEventListener('change', update);
    update();
})();
</script>
@endpush
