@extends('layouts.erp')

@section('title', 'New Voucher')

@section('content')
@php
    $rows = old('entries', [['account_id' => '', 'debit' => '', 'credit' => ''], ['account_id' => '', 'debit' => '', 'credit' => '']]);
@endphp
<div class="container-fluid py-3">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
        <div>
            <h2 class="h4 mb-1">New Voucher</h2>
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

    <form method="POST" action="{{ route('companies.vouchers.store', $company) }}">
        @csrf

        <div class="card border-0 shadow-sm">
            <div class="card-body p-3 p-md-4">
                <div class="row g-3 mb-4">
                    <div class="col-12 col-md-4">
                        <label class="form-label">Type *</label>
                        <select name="type" class="form-select" required>
                            @foreach (\App\Models\Voucher::TYPES as $value => $label)
                                <option value="{{ $value }}" @selected(old('type', $type) === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-6 col-md-4">
                        <label class="form-label">Date *</label>
                        <input type="date" name="date" class="form-control" required
                            value="{{ old('date', now()->toDateString()) }}">
                    </div>
                    <div class="col-6 col-md-4">
                        <label class="form-label">Reference</label>
                        <input name="reference" class="form-control" maxlength="255" value="{{ old('reference') }}">
                    </div>
                </div>

                <div class="table-responsive">
                    <table class="table align-middle mb-2" id="entries">
                        <thead class="table-light">
                            <tr>
                                <th style="min-width: 220px">Ledger</th>
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
                                    </td>
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
                                <td>
                                    <button type="button" class="btn btn-sm btn-outline-primary" id="add-row">+ Add row</button>
                                </td>
                                <td class="text-end" id="total-debit">0.00</td>
                                <td class="text-end" id="total-credit">0.00</td>
                                <td></td>
                            </tr>
                            <tr>
                                <td colspan="4" class="text-end small" id="difference"></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                <label class="form-label">Narration</label>
                <textarea name="narration" rows="2" maxlength="2000" class="form-control">{{ old('narration') }}</textarea>
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
    let next = body.rows.length;

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
            el.value = '';
        });
        next++;
        body.appendChild(row);
    });

    body.addEventListener('click', e => {
        if (e.target.classList.contains('js-remove') && body.rows.length > 2) {
            e.target.closest('tr').remove();
            recalc();
        }
    });

    body.addEventListener('input', recalc);
    recalc();
})();
</script>
@endsection
