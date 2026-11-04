@extends('layouts.erp')

@php
    $titles = ['sales' => 'Sales Invoice', 'purchase' => 'Purchase Invoice', 'credit_note' => 'Credit Note (Sales Return)', 'debit_note' => 'Debit Note (Purchase Return)'];
    $salesSide = in_array($type, ['sales', 'credit_note'], true);
@endphp

@section('title', $titles[$type])

@section('content')
@php
    $rows = old('lines', [['account_id' => '', 'description' => '', 'hsn_sac' => '', 'taxable' => '', 'rate' => '18']]);
    $itemData = $items->mapWithKeys(fn ($a) => [$a->id => ['hsn' => $a->hsn_sac, 'rate' => $a->gst_rate === null ? null : (float) $a->gst_rate]]);
    $partyData = $parties->mapWithKeys(fn ($a) => [$a->id => $a->state_code]);
    $stockData = $stockItems->mapWithKeys(fn ($i) => [$i->id => ['hsn' => $i->hsn_sac, 'rate' => $i->gst_rate === null ? null : (float) $i->gst_rate, 'price' => (float) $i->opening_rate]]);
@endphp
<div class="container-fluid py-3">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
        <div>
            <h2 class="h4 mb-1">{{ $titles[$type] }} (GST)</h2>
            <p class="text-secondary mb-0">
                {{ $company->name }}
                · {{ \App\Support\GstStates::name($company->stateCode()) ?? 'State not set' }}
            </p>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <div class="btn-group" role="group" aria-label="Voucher type">
                @foreach (['sales' => 'Sales', 'purchase' => 'Purchase', 'credit_note' => 'Credit Note', 'debit_note' => 'Debit Note'] as $value => $label)
                    <a href="{{ route('companies.gst-invoices.create', [$company, 'type' => $value]) }}"
                        class="btn btn-sm {{ $type === $value ? 'btn-primary' : 'btn-outline-primary' }}">{{ $label }}</a>
                @endforeach
            </div>
            <a href="{{ route('companies.vouchers.index', $company) }}" class="btn btn-outline-secondary">Back to Day Book</a>
        </div>
    </div>

    @unless ($company->stateCode())
        <div class="alert alert-warning">
            Add the company's GSTIN or state first, so GST can be split into CGST/SGST or IGST.
            <a href="{{ route('companies.edit', $company) }}">Edit company</a>
        </div>
    @endunless

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

    @if ($items->isEmpty())
        <div class="alert alert-warning">
            Create a ledger under {{ $salesSide ? 'Sales Accounts' : 'Purchase Accounts' }} first.
            <a href="{{ route('companies.accounts.create', $company) }}">Create ledger</a>
        </div>
    @endif

    <form method="POST" action="{{ route('companies.gst-invoices.store', $company) }}">
        @csrf
        <input type="hidden" name="type" value="{{ $type }}">
        @if (old('document_id'))
            <input type="hidden" name="document_id" value="{{ old('document_id') }}">
            <div class="alert alert-info">Converting {{ old('reference') }}. Check the details and save to create the invoice.</div>
        @endif

        <div class="card border-0 shadow-sm">
            <div class="card-body p-3 p-md-4">
                <div class="row g-3 mb-4">
                    <div class="col-12 col-md-4">
                        <label class="form-label" for="party">Party *</label>
                        <select name="party_account_id" id="party" class="form-select" required>
                            <option value="">Select party / cash / bank</option>
                            @foreach ($parties as $party)
                                <option value="{{ $party->id }}" @selected((string) old('party_account_id') === (string) $party->id)>
                                    {{ $party->name }} ({{ $party->group->name }})
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-12 col-md-3">
                        <label class="form-label" for="pos">Place of supply</label>
                        <select name="place_of_supply" id="pos" class="form-select">
                            <option value="">From party</option>
                            @foreach ($states as $code => $name)
                                <option value="{{ $code }}" @selected(old('place_of_supply') === $code)>{{ $code }} - {{ $name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-6 col-md-2">
                        <label class="form-label" for="f-date">Date *</label>
                        <input id="f-date" type="date" name="date" class="form-control" required value="{{ old('date', now()->toDateString()) }}">
                    </div>
                    <div class="col-6 col-md-3">
                        <label class="form-label" for="f-reference">{{ match ($type) { 'sales' => 'Reference', 'purchase' => 'Supplier invoice no.', default => 'Against invoice no.' } }}</label>
                        <input id="f-reference" name="reference" class="form-control" maxlength="255" value="{{ old('reference') }}">
                    </div>
                </div>

                <div class="table-responsive">
                    <table class="table align-middle mb-2" id="lines">
                        <thead class="table-light">
                            <tr>
                                <th style="min-width: 200px">{{ $salesSide ? 'Sales' : 'Purchase / expense' }} ledger</th>
                                <th style="min-width: 170px">Stock item</th>
                                <th style="min-width: 140px">Description</th>
                                <th style="width: 120px">HSN/SAC</th>
                                <th style="width: 100px" class="text-end">Qty</th>
                                <th style="width: 120px" class="text-end">Rate</th>
                                <th style="width: 140px" class="text-end">Amount</th>
                                <th style="width: 90px" class="text-end">Disc %</th>
                                <th style="width: 110px">GST %</th>
                                <th style="width: 130px" class="text-end">Tax</th>
                                <th style="width: 50px"></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($rows as $i => $row)
                                <tr>
                                    <td>
                                        <select name="lines[{{ $i }}][account_id]" class="form-select js-item" required>
                                            <option value="">Select ledger</option>
                                            @foreach ($items as $item)
                                                <option value="{{ $item->id }}" @selected((string) ($row['account_id'] ?? '') === (string) $item->id)>{{ $item->name }}</option>
                                            @endforeach
                                        </select>
                                    </td>
                                    <td>
                                        <select name="lines[{{ $i }}][stock_item_id]" class="form-select js-stock">
                                            <option value="">— none —</option>
                                            @foreach ($stockItems as $stockItem)
                                                <option value="{{ $stockItem->id }}" @selected((string) ($row['stock_item_id'] ?? '') === (string) $stockItem->id)>{{ $stockItem->name }} ({{ $stockItem->unit->symbol }})</option>
                                            @endforeach
                                        </select>
                                    </td>
                                    <td><input name="lines[{{ $i }}][description]" value="{{ $row['description'] ?? '' }}" class="form-control" maxlength="255"></td>
                                    <td><input name="lines[{{ $i }}][hsn_sac]" value="{{ $row['hsn_sac'] ?? '' }}" class="form-control js-hsn" maxlength="10"></td>
                                    <td><input type="number" step="0.001" min="0" name="lines[{{ $i }}][quantity]" value="{{ $row['quantity'] ?? '' }}" class="form-control text-end js-qty"></td>
                                    <td><input type="number" step="0.01" min="0" name="lines[{{ $i }}][item_rate]" value="{{ $row['item_rate'] ?? '' }}" class="form-control text-end js-price"></td>
                                    <td><input type="number" step="0.01" min="0.01" name="lines[{{ $i }}][taxable]" value="{{ $row['taxable'] ?? '' }}" class="form-control text-end js-taxable" aria-label="Amount"></td>
                                    <td><input type="number" step="0.01" min="0" max="100" name="lines[{{ $i }}][discount_percent]" value="{{ $row['discount_percent'] ?? '' }}" class="form-control text-end js-disc" aria-label="Discount %"></td>
                                    <td>
                                        <select name="lines[{{ $i }}][rate]" class="form-select js-rate">
                                            @foreach ($rates as $rate)
                                                <option value="{{ $rate }}" @selected((string) ($row['rate'] ?? '') === (string) $rate)>{{ $rate }}</option>
                                            @endforeach
                                        </select>
                                    </td>
                                    <td class="text-end js-tax">0.00</td>
                                    <td><button type="button" class="btn btn-sm btn-outline-danger js-remove" title="Remove line">&times;</button></td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot>
                            <tr class="fw-semibold">
                                <td colspan="6"><button type="button" class="btn btn-sm btn-outline-primary" id="add-line">+ Add line</button></td>
                                <td class="text-end" colspan="2">Taxable <span id="total-taxable">0.00</span></td>
                                <td></td>
                                <td class="text-end" id="total-tax">0.00</td>
                                <td></td>
                            </tr>
                            <tr class="fw-semibold">
                                <td colspan="9" class="text-end">Invoice total <span class="text-secondary small" id="split"></span></td>
                                <td class="text-end" id="grand-total">0.00</td>
                                <td></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                <label class="form-label" for="f-narration">Narration</label>
                <textarea id="f-narration" name="narration" rows="2" maxlength="2000" class="form-control">{{ old('narration') }}</textarea>
            </div>
            <div class="card-footer bg-white text-end">
                <button class="btn btn-primary">Save Invoice</button>
            </div>
        </div>
    </form>
</div>

<script>
(() => {
    const items = @json($itemData);
    const parties = @json($partyData);
    const stock = @json($stockData);
    const companyState = @json($company->stateCode());
    const body = document.querySelector('#lines tbody');
    let next = body.rows.length;
    const fmt = n => n.toFixed(2);

    function interState() {
        const pos = document.getElementById('pos').value || parties[document.getElementById('party').value] || companyState;
        return pos !== companyState;
    }

    function recalc() {
        let taxable = 0, tax = 0;
        for (const row of body.rows) {
            // A stock line's taxable value is quantity x rate.
            const hasItem = row.querySelector('.js-stock').value !== '';
            const taxInput = row.querySelector('.js-taxable');
            taxInput.readOnly = hasItem;
            if (hasItem) {
                const q = parseFloat(row.querySelector('.js-qty').value) || 0;
                const p = parseFloat(row.querySelector('.js-price').value) || 0;
                taxInput.value = q && p ? (Math.round(q * p * 100) / 100).toFixed(2) : '';
            }
            // Discount comes off before GST.
            const gross = parseFloat(row.querySelector('.js-taxable').value) || 0;
            const disc = parseFloat(row.querySelector('.js-disc').value) || 0;
            const t = Math.round(gross * (1 - disc / 100) * 100) / 100;
            const r = parseFloat(row.querySelector('.js-rate').value) || 0;
            const lineTax = interState() ? Math.round(t * r) / 100 : 2 * Math.round(t * r / 2) / 100;
            row.querySelector('.js-tax').textContent = fmt(lineTax);
            taxable += t;
            tax += lineTax;
        }
        document.getElementById('total-taxable').textContent = fmt(taxable);
        document.getElementById('total-tax').textContent = fmt(tax);
        document.getElementById('grand-total').textContent = fmt(taxable + tax);
        document.getElementById('split').textContent = interState() ? '(IGST)' : '(CGST + SGST)';
    }

    document.getElementById('add-line').addEventListener('click', () => {
        const row = body.rows[0].cloneNode(true);
        row.querySelectorAll('select, input').forEach(el => {
            el.name = el.name.replace(/lines\[\d+\]/, 'lines[' + next + ']');
            if (!el.classList.contains('js-rate')) el.value = '';
        });
        next++;
        body.appendChild(row);
        recalc();
    });

    body.addEventListener('click', e => {
        if (e.target.classList.contains('js-remove') && body.rows.length > 1) {
            e.target.closest('tr').remove();
            recalc();
        }
    });

    // Picking a ledger fills its default HSN and rate.
    body.addEventListener('change', e => {
        if (e.target.classList.contains('js-item') && items[e.target.value]) {
            const row = e.target.closest('tr'), item = items[e.target.value];
            if (item.hsn) row.querySelector('.js-hsn').value = item.hsn;
            if (item.rate !== null) row.querySelector('.js-rate').value = String(item.rate);
        }
        if (e.target.classList.contains('js-stock') && stock[e.target.value]) {
            const row = e.target.closest('tr'), s = stock[e.target.value];
            if (s.hsn) row.querySelector('.js-hsn').value = s.hsn;
            if (s.rate !== null) row.querySelector('.js-rate').value = String(s.rate);
            if (!row.querySelector('.js-price').value && s.price) row.querySelector('.js-price').value = s.price;
        }
        recalc();
    });

    body.addEventListener('input', recalc);
    document.getElementById('party').addEventListener('change', recalc);
    document.getElementById('pos').addEventListener('change', recalc);
    recalc();
})();
</script>
@endsection
