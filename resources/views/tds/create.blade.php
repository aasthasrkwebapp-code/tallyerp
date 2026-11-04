@extends('layouts.erp')

@section('title', 'Bill with TDS')

@section('content')
@php
    $partyData = $parties->mapWithKeys(fn ($p) => [$p->id => [
        'label' => \App\Support\Tds::label($p->tds_section),
        'rate' => \App\Support\Tds::rate($p->tds_section, $p->pan),
        'pan' => $p->pan,
    ]]);
@endphp
<div class="container-fluid py-3">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
        <div>
            <h2 class="h4 mb-1">Book Bill with TDS</h2>
            <p class="text-secondary mb-0">{{ $company->name }} · FY {{ $company->financial_year }}</p>
        </div>
        <a href="{{ route('companies.tds.index', $company) }}" class="btn btn-outline-secondary">TDS Report</a>
    </div>

    @if ($errors->any())
        <div class="alert alert-danger">
            <ul class="mb-0">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @if ($parties->isEmpty())
        <div class="alert alert-warning">
            No supplier has a TDS section yet. Edit a supplier ledger and choose its TDS section.
            <a href="{{ route('companies.accounts.index', $company) }}">Ledgers</a>
        </div>
    @endif

    <form method="POST" action="{{ route('companies.tds.store', $company) }}">
        @csrf
        <div class="card border-0 shadow-sm">
            <div class="card-body p-3 p-md-4">
                <div class="row g-3">
                    <div class="col-12 col-md-6">
                        <label class="form-label" for="party">Supplier *</label>
                        <select name="party_account_id" id="party" class="form-select" required>
                            <option value="">Select supplier</option>
                            @foreach ($parties as $party)
                                <option value="{{ $party->id }}" @selected((string) old('party_account_id') === (string) $party->id)>{{ $party->name }}</option>
                            @endforeach
                        </select>
                        <div class="form-text" id="party-info"></div>
                    </div>
                    <div class="col-12 col-md-6">
                        <label class="form-label" for="f-expense-account-id">Expense ledger *</label>
                        <select id="f-expense-account-id" name="expense_account_id" class="form-select" required>
                            <option value="">Select ledger</option>
                            @foreach ($expenses as $expense)
                                <option value="{{ $expense->id }}" @selected((string) old('expense_account_id') === (string) $expense->id)>{{ $expense->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-6 col-md-3">
                        <label class="form-label" for="f-date">Date *</label>
                        <input id="f-date" type="date" name="date" class="form-control" required value="{{ old('date', now()->toDateString()) }}">
                    </div>
                    <div class="col-6 col-md-3">
                        <label class="form-label" for="amount">Bill amount *</label>
                        <input type="number" step="0.01" min="0.01" name="amount" id="amount" class="form-control text-end" required value="{{ old('amount') }}">
                    </div>
                    <div class="col-12 col-md-6">
                        <label class="form-label" for="f-reference">Supplier bill no.</label>
                        <input id="f-reference" name="reference" class="form-control" maxlength="255" value="{{ old('reference') }}">
                    </div>
                    <div class="col-12">
                        <div class="row text-center g-2" id="preview">
                            <div class="col-4"><div class="small text-secondary">TDS</div><div class="fw-semibold" id="tds">0.00</div></div>
                            <div class="col-4"><div class="small text-secondary">Payable to supplier</div><div class="fw-semibold" id="net">0.00</div></div>
                            <div class="col-4"><div class="small text-secondary">Rate</div><div class="fw-semibold" id="rate">—</div></div>
                        </div>
                    </div>
                    <div class="col-12">
                        <label class="form-label" for="f-narration">Narration</label>
                        <textarea id="f-narration" name="narration" rows="2" maxlength="2000" class="form-control">{{ old('narration') }}</textarea>
                    </div>
                </div>
            </div>
            <div class="card-footer bg-white text-end">
                <button class="btn btn-primary">Save Bill</button>
            </div>
        </div>
    </form>
</div>

<script>
(() => {
    const parties = @json($partyData);
    const party = document.getElementById('party');
    const amount = document.getElementById('amount');

    function update() {
        const p = parties[party.value];
        const a = parseFloat(amount.value) || 0;
        const tds = p ? Math.round(a * p.rate / 100) : 0;
        document.getElementById('tds').textContent = tds.toFixed(2);
        document.getElementById('net').textContent = (a - tds).toFixed(2);
        document.getElementById('rate').textContent = p ? p.rate + '%' : '—';
        document.getElementById('party-info').textContent = p
            ? p.label + (p.pan ? ' · PAN ' + p.pan : ' · No PAN: 20% applies')
            : '';
    }

    party.addEventListener('change', update);
    amount.addEventListener('input', update);
    update();
})();
</script>
@endsection
