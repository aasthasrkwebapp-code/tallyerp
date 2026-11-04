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
                        <label class="form-label">Name *</label>
                        <input name="name" value="{{ old('name', $account->name) }}"
                            class="form-control" required maxlength="255">
                    </div>

                    <div class="col-12 col-md-6">
                        <label class="form-label">Code</label>
                        <input name="code" value="{{ old('code', $account->code) }}"
                            class="form-control" maxlength="50">
                    </div>

                    <div class="col-12 col-md-6">
                        <label class="form-label">Under *</label>
                        <select name="account_group_id" class="form-select" required>
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
                        <label class="form-label">Opening Balance</label>
                        <input type="number" step="0.01" min="0" name="opening_balance"
                            value="{{ old('opening_balance', $account->opening_balance ?? 0) }}"
                            class="form-control">
                    </div>

                    <div class="col-4 col-md-2">
                        <label class="form-label">Dr / Cr</label>
                        <select name="opening_balance_type" class="form-select">
                            @foreach (['dr' => 'Dr', 'cr' => 'Cr'] as $value => $label)
                                <option value="{{ $value }}"
                                    @selected(old('opening_balance_type', $account->opening_balance_type) === $value)>
                                    {{ $label }}
                                </option>
                            @endforeach
                        </select>
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
