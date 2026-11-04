@extends('layouts.erp')

@section('title', $voucher->exists ? 'Edit Voucher' : 'New Voucher')

@section('content')
@php
    $blank = ['account_id' => '', 'cost_centre_id' => '', 'debit' => '', 'credit' => '', 'foreign_amount' => '', 'exchange_rate' => '', 'side' => 'dr'];
    $ledgerCurrencies = $accounts->whereNotNull('currency_code')->pluck('currency_code', 'id');
    $rows = old('entries', $voucher->exists
        ? $voucher->entries->map(fn ($e) => [
            'account_id' => $e->account_id,
            'cost_centre_id' => $e->cost_centre_id,
            'debit' => $e->debit > 0 ? (float) $e->debit : '',
            'credit' => $e->credit > 0 ? (float) $e->credit : '',
            'foreign_amount' => $e->foreign_amount !== null ? (float) $e->foreign_amount : '',
            'exchange_rate' => $e->exchange_rate !== null ? (float) $e->exchange_rate : '',
            'side' => $e->credit > 0 ? 'cr' : 'dr',
        ])->all()
        : [$blank, $blank]);
@endphp
<div class="container-fluid py-3">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
        <div>
            <h2 class="h4 mb-1">{{ $voucher->exists ? 'Edit '.$voucher->typeLabel().' #'.$voucher->number : 'New Voucher' }}</h2>
            <p class="text-secondary mb-0">{{ $company->name }} · FY {{ $company->financial_year }}</p>
        </div>
        <a href="{{ route('companies.vouchers.index', $company) }}" class="btn btn-outline-secondary">
            Back to Day Book
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

    @if ($accounts->count() < 2)
        <div class="alert alert-warning">
            You need at least two active ledgers to record a voucher.
            <a href="{{ route('companies.accounts.create', $company) }}">Create a ledger</a>
        </div>
    @endif

    <form method="POST"
        action="{{ $voucher->exists ? route('companies.vouchers.update', [$company, $voucher]) : route('companies.vouchers.store', $company) }}">
        @csrf
        @if ($voucher->exists)
            @method('PUT')
        @endif

        <div class="card border-0 shadow-sm">
            <div class="card-body p-3 p-md-4">
                <div class="row g-3 mb-4">
                    <div class="col-12 col-md-4">
                        <label class="form-label">Type *</label>
                        <select name="type" class="form-select" required @disabled($voucher->exists)>
                            @foreach (\App\Models\Voucher::TYPES as $value => $label)
                                <option value="{{ $value }}" @selected(old('type', $type) === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-6 col-md-4">
                        <label class="form-label">Date *</label>
                        <input type="date" name="date" class="form-control" required
                            value="{{ old('date', $voucher->date?->toDateString() ?? now()->toDateString()) }}">
                    </div>
                    <div class="col-6 col-md-4">
                        <label class="form-label">Reference</label>
                        <input name="reference" class="form-control" maxlength="255" value="{{ old('reference', $voucher->reference) }}">
                    </div>
                </div>

                <div class="table-responsive">
                    <table class="table align-middle mb-2" id="entries">
                        <thead class="table-light">
                            <tr>
                                <th style="min-width: 220px">Ledger</th>
                                @if ($costCentres->isNotEmpty())
                                    <th style="min-width: 160px">Cost centre</th>
                                @endif
                                <th style="width: 160px" class="text-end">Debit</th>
                                <th style="width: 160px" class="text-end">Credit</th>
                                <th style="width: 50px"></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($rows as $i => $row)
                                <tr>
                                    <td>
                                        <select name="entries[{{ $i }}][account_id]" class="form-select">
                                            <option value="">Select ledger</option>
                                            @foreach ($accounts as $account)
                                                <option value="{{ $account->id }}"
                                                    @selected((string) ($row['account_id'] ?? '') === (string) $account->id)>
                                                    {{ $account->name }} ({{ $account->group->name }})
                                                </option>
                                            @endforeach
                                        </select>
                                        @php $fx = $ledgerCurrencies[$row['account_id'] ?? ''] ?? null; @endphp
                                        <div class="input-group input-group-sm mt-1 js-fx {{ $fx ? '' : 'd-none' }}">
                                            <select name="entries[{{ $i }}][side]" class="form-select js-side" style="max-width: 70px" aria-label="Debit or credit" @disabled(! $fx)>
                                                <option value="dr" @selected(($row['side'] ?? 'dr') === 'dr')>Dr</option>
                                                <option value="cr" @selected(($row['side'] ?? '') === 'cr')>Cr</option>
                                            </select>
                                            <span class="input-group-text js-fx-code">{{ $fx }}</span>
                                            <input type="number" step="0.01" min="0" name="entries[{{ $i }}][foreign_amount]" value="{{ $row['foreign_amount'] ?? '' }}"
                                                class="form-control text-end js-fx-amount" placeholder="Amount" aria-label="Foreign amount" @disabled(! $fx)>
                                            <span class="input-group-text">@</span>
                                            <input type="number" step="0.000001" min="0" name="entries[{{ $i }}][exchange_rate]" value="{{ $row['exchange_rate'] ?? '' }}"
                                                class="form-control text-end js-fx-rate" placeholder="Rate" aria-label="Exchange rate" @disabled(! $fx)>
                                        </div>
                                    </td>
                                    @if ($costCentres->isNotEmpty())
                                        <td>
                                            <select name="entries[{{ $i }}][cost_centre_id]" class="form-select">
                                                <option value="">—</option>
                                                @foreach ($costCentres as $centre)
                                                    <option value="{{ $centre->id }}" @selected((string) ($row['cost_centre_id'] ?? '') === (string) $centre->id)>{{ $centre->name }}</option>
                                                @endforeach
                                            </select>
                                        </td>
                                    @endif
                                    <td>
                                        <input type="number" step="0.01" min="0" name="entries[{{ $i }}][debit]"
                                            value="{{ $row['debit'] ?? '' }}" class="form-control text-end js-debit">
                                    </td>
                                    <td>
                                        <input type="number" step="0.01" min="0" name="entries[{{ $i }}][credit]"
                                            value="{{ $row['credit'] ?? '' }}" class="form-control text-end js-credit">
                                    </td>
                                    <td>
                                        <button type="button" class="btn btn-sm btn-outline-danger js-remove" title="Remove row">&times;</button>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot>
                            <tr class="fw-semibold">
                                <td colspan="{{ $costCentres->isNotEmpty() ? 2 : 1 }}">
                                    <button type="button" class="btn btn-sm btn-outline-primary" id="add-row">+ Add row</button>
                                </td>
                                <td class="text-end" id="total-debit">0.00</td>
                                <td class="text-end" id="total-credit">0.00</td>
                                <td></td>
                            </tr>
                            <tr>
                                <td colspan="{{ $costCentres->isNotEmpty() ? 5 : 4 }}" class="text-end small" id="difference"></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                <label class="form-label">Narration</label>
                <textarea name="narration" rows="2" maxlength="2000" class="form-control">{{ old('narration', $voucher->narration) }}</textarea>
            </div>
            <div class="card-footer bg-white text-end">
                <button class="btn btn-primary">Save Voucher</button>
            </div>
        </div>
    </form>
</div>

<script>
(() => {
    const body = document.querySelector('#entries tbody');
    const currencies = @json($ledgerCurrencies);
    let next = body.rows.length;

    // Foreign-currency ledgers: rupees = amount × rate, on the chosen side.
    function syncFx(row) {
        const code = currencies[row.querySelector('select[name$="[account_id]"]').value];
        const box = row.querySelector('.js-fx');
        box.classList.toggle('d-none', !code);
        box.querySelectorAll('select, input').forEach(el => el.disabled = !code);
        row.querySelector('.js-fx-code').textContent = code || '';

        const debit = row.querySelector('.js-debit'), credit = row.querySelector('.js-credit');
        debit.readOnly = credit.readOnly = !!code;
        if (!code) return;

        const rupees = (parseFloat(row.querySelector('.js-fx-amount').value) || 0) * (parseFloat(row.querySelector('.js-fx-rate').value) || 0);
        const value = rupees ? (Math.round(rupees * 100) / 100).toFixed(2) : '';
        const isCredit = row.querySelector('.js-side').value === 'cr';
        debit.value = isCredit ? '' : value;
        credit.value = isCredit ? value : '';
    }

    const fmt = n => n.toFixed(2);
    const sum = cls => [...body.querySelectorAll(cls)].reduce((t, el) => t + (parseFloat(el.value) || 0), 0);

    function recalc() {
        const dr = sum('.js-debit'), cr = sum('.js-credit');
        document.getElementById('total-debit').textContent = fmt(dr);
        document.getElementById('total-credit').textContent = fmt(cr);
        const diff = Math.round((dr - cr) * 100) / 100;
        const el = document.getElementById('difference');
        el.textContent = diff === 0 ? '' : 'Difference: ' + fmt(Math.abs(diff)) + (diff > 0 ? ' Dr' : ' Cr');
        el.className = 'text-end small ' + (diff === 0 ? '' : 'text-danger');
    }

    document.getElementById('add-row').addEventListener('click', () => {
        const row = body.rows[0].cloneNode(true);
        row.querySelectorAll('select, input').forEach(el => {
            el.name = el.name.replace(/entries\[\d+\]/, 'entries[' + next + ']');
            el.value = el.classList.contains('js-side') ? 'dr' : '';
        });
        next++;
        body.appendChild(row);
        syncFx(row);
    });

    body.addEventListener('click', e => {
        if (e.target.classList.contains('js-remove') && body.rows.length > 2) {
            e.target.closest('tr').remove();
            recalc();
        }
    });

    body.addEventListener('input', e => {
        if (e.target.closest('.js-fx')) syncFx(e.target.closest('tr'));
        recalc();
    });
    body.addEventListener('change', e => {
        syncFx(e.target.closest('tr'));
        recalc();
    });
    [...body.rows].forEach(syncFx);
    recalc();
})();
</script>
@endsection
