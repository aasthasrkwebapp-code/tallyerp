@extends('layouts.erp')

@section('title', ($recurring->exists ? 'Alter ' : 'New ').'recurring voucher')

@section('content')
<div class="page-header">
    <div>
        <h1>{{ $recurring->exists ? 'Alter recurring voucher' : 'New recurring voucher' }}</h1>
        <p class="text-secondary mb-0">The lines are checked like a voucher: total debits must equal total credits.</p>
    </div>
    <a href="{{ $recurring->exists ? route('recurring-vouchers.show', $recurring) : route('recurring-vouchers.index') }}" class="btn btn-outline-secondary">Back</a>
</div>

@if ($errors->any())
    <div class="alert alert-danger small"><ul class="mb-0">@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>
@endif

<form method="POST" action="{{ $recurring->exists ? route('recurring-vouchers.update', $recurring) : route('recurring-vouchers.store') }}" novalidate>
    @csrf
    @if ($recurring->exists) @method('PUT') @endif

    <div class="erp-card p-3 mb-3">
        <div class="row g-3">
            <div class="col-12 col-md-6 col-lg-4">
                <label for="name" class="form-label">Name</label>
                <input id="name" name="name" value="{{ $input['name'] ?? '' }}" class="form-control @error('name') is-invalid @enderror" maxlength="100" required placeholder="E.g. Office rent">
            </div>
            <div class="col-12 col-md-6 col-lg-3">
                <label for="voucher_type_id" class="form-label">Voucher type</label>
                <select id="voucher_type_id" name="voucher_type_id" class="form-select @error('voucher_type_id') is-invalid @enderror">
                    @foreach ($types as $t)<option value="{{ $t->id }}" @selected((int) ($input['voucher_type_id'] ?? 0) === $t->id)>{{ $t->name }}</option>@endforeach
                </select>
            </div>
            <div class="col-6 col-md-4 col-lg-2">
                <label for="frequency" class="form-label">Repeats</label>
                <select id="frequency" name="frequency" class="form-select">
                    @foreach (\App\Models\RecurringVoucher::FREQUENCIES as $k => $label)<option value="{{ $k }}" @selected(($input['frequency'] ?? '') === $k)>{{ $label }}</option>@endforeach
                </select>
            </div>
            <div class="col-6 col-md-4 col-lg-3">
                <label for="mode" class="form-label">Each voucher is</label>
                <select id="mode" name="mode" class="form-select">
                    @foreach (\App\Models\RecurringVoucher::MODES as $k => $label)<option value="{{ $k }}" @selected(($input['mode'] ?? 'post') === $k)>{{ $label }}</option>@endforeach
                </select>
            </div>
            <div class="col-6 col-md-4 col-lg-2">
                <label for="start_date" class="form-label">First date</label>
                <input id="start_date" type="date" name="start_date" value="{{ $input['start_date'] ?? '' }}" class="form-control @error('start_date') is-invalid @enderror" required>
            </div>
            @if ($recurring->exists)
                <div class="col-6 col-md-4 col-lg-2">
                    <label for="next_date" class="form-label">Next date</label>
                    <input id="next_date" type="date" name="next_date" value="{{ $input['next_date'] ?? '' }}" class="form-control @error('next_date') is-invalid @enderror">
                </div>
            @endif
            <div class="col-6 col-md-4 col-lg-2">
                <label for="end_date" class="form-label">Last date <span class="text-secondary small">(optional)</span></label>
                <input id="end_date" type="date" name="end_date" value="{{ $input['end_date'] ?? '' }}" class="form-control @error('end_date') is-invalid @enderror">
            </div>
            <div class="col-6 col-md-4 col-lg-3">
                <label for="reference" class="form-label">Reference</label>
                <input id="reference" name="reference" value="{{ $input['reference'] ?? '' }}" class="form-control" maxlength="100" placeholder="E.g. lease agreement no.">
            </div>
            <div class="col-12 col-lg-3 d-flex align-items-end">
                <div class="form-check form-switch">
                    <input type="hidden" name="is_active" value="0">
                    <input class="form-check-input" type="checkbox" role="switch" id="is_active" name="is_active" value="1" @checked($input['is_active'] ?? true)>
                    <label class="form-check-label" for="is_active">Active</label>
                </div>
            </div>
        </div>
        <p class="small text-secondary mt-2 mb-0">Monthly and longer schedules keep the first date's day of the month. A 31st falls back to the last day of shorter months.</p>
    </div>

    <div class="erp-card mb-3">
        <div class="erp-card-header d-flex justify-content-between align-items-center">
            <span>Lines</span>
            <button type="button" class="btn btn-sm btn-outline-primary" id="add-rline" data-shortcut="a"><i class="bi bi-plus-lg"></i> Add line</button>
        </div>
        <div id="rlines" class="p-2 p-md-3">
            @foreach ($input['entries'] as $i => $e)
                <div class="row g-2 mb-2 pb-2 border-bottom rline">
                    <div class="col-4 col-md-1">
                        <label class="form-label small d-md-none">Dr/Cr</label>
                        <select name="entries[{{ $i }}][side]" class="form-select" aria-label="Debit or credit">
                            <option value="Dr" @selected(($e['side'] ?? 'Dr') === 'Dr')>Dr</option>
                            <option value="Cr" @selected(($e['side'] ?? '') === 'Cr')>Cr</option>
                        </select>
                    </div>
                    <div class="col-8 col-md-4">
                        <label class="form-label small d-md-none">Ledger</label>
                        <select name="entries[{{ $i }}][account_id]" class="form-select @error("entries.$i.account_id") is-invalid @enderror" aria-label="Ledger">
                            <option value="">Select ledger…</option>
                            @foreach ($accounts as $a)<option value="{{ $a->id }}" @selected((int) ($e['account_id'] ?? 0) === $a->id)>{{ $a->name }}</option>@endforeach
                        </select>
                    </div>
                    <div class="col-6 col-md-2">
                        <label class="form-label small d-md-none">Amount</label>
                        <input name="entries[{{ $i }}][amount]" value="{{ $e['amount'] ?? '' }}" inputmode="decimal" class="form-control text-end @error("entries.$i.amount") is-invalid @enderror" aria-label="Amount" autocomplete="off">
                    </div>
                    <div class="col-6 col-md-2">
                        <label class="form-label small d-md-none">Bill-wise</label>
                        <select name="entries[{{ $i }}][bill_type]" class="form-select" aria-label="Bill-wise reference">
                            <option value="">No bill / on account</option>
                            <option value="new_ref" @selected(($e['bill_type'] ?? '') === 'new_ref')>New bill (voucher no.)</option>
                            <option value="on_account" @selected(($e['bill_type'] ?? '') === 'on_account')>On account</option>
                        </select>
                    </div>
                    <div class="col-6 col-md-1">
                        <label class="form-label small d-md-none">Due in days</label>
                        <input name="entries[{{ $i }}][due_days]" value="{{ $e['due_days'] ?? '' }}" inputmode="numeric" class="form-control" placeholder="Due days" aria-label="Bill due in days">
                    </div>
                    <div class="col-6 col-md-2">
                        <label class="form-label small d-md-none">Cost centre</label>
                        <select name="entries[{{ $i }}][cost_centre_id]" class="form-select" aria-label="Cost centre">
                            <option value="">No cost centre</option>
                            @foreach ($costCentres as $c)<option value="{{ $c->id }}" @selected((int) ($e['cost_centre_id'] ?? 0) === $c->id)>{{ $c->name }}</option>@endforeach
                        </select>
                    </div>
                    <div class="col-12">
                        <input name="entries[{{ $i }}][narration]" value="{{ $e['narration'] ?? '' }}" class="form-control form-control-sm" maxlength="500" placeholder="Line narration (optional)" aria-label="Line narration">
                    </div>
                </div>
            @endforeach
        </div>
        <div class="border-top px-3 py-2 d-flex flex-wrap justify-content-end gap-4 small">
            <div>Total Dr: <strong id="rtotal-dr" class="amount">0.00</strong></div>
            <div>Total Cr: <strong id="rtotal-cr" class="amount">0.00</strong></div>
        </div>
    </div>

    <div class="erp-card p-3 mb-3">
        <label for="narration" class="form-label">Narration</label>
        <textarea id="narration" name="narration" rows="2" class="form-control" maxlength="2000">{{ $input['narration'] ?? '' }}</textarea>
    </div>

    <button class="btn btn-primary mb-5" data-shortcut="s">Save recurring voucher</button>
</form>

@push('scripts')
<script>
(function () {
    var box = document.getElementById('rlines');
    function totals() {
        var dr = 0, cr = 0;
        box.querySelectorAll('.rline').forEach(function (row) {
            var amt = parseFloat((row.querySelector('[name$="[amount]"]').value || '').replace(/,/g, '')) || 0;
            if (row.querySelector('[name$="[side]"]').value === 'Dr') dr += amt; else cr += amt;
        });
        document.getElementById('rtotal-dr').textContent = dr.toFixed(2);
        document.getElementById('rtotal-cr').textContent = cr.toFixed(2);
        document.getElementById('rtotal-cr').classList.toggle('text-danger', Math.abs(dr - cr) > 0.004);
    }
    box.addEventListener('input', totals);
    box.addEventListener('change', totals);
    document.getElementById('add-rline').addEventListener('click', function () {
        var rows = box.querySelectorAll('.rline');
        var copy = rows[rows.length - 1].cloneNode(true);
        var n = rows.length;
        copy.querySelectorAll('[name]').forEach(function (el) {
            el.name = el.name.replace(/entries\[\d+\]/, 'entries[' + n + ']');
            if (el.tagName === 'SELECT') el.selectedIndex = 0; else el.value = '';
            el.classList.remove('is-invalid');
        });
        box.appendChild(copy);
        copy.querySelector('[name$="[account_id]"]').focus();
    });
    totals();
})();
</script>
@endpush
@endsection
