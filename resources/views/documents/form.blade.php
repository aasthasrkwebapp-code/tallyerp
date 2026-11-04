@extends('layouts.erp')

@php
    $label = \App\Models\BusinessDocument::TYPES[$type][0];
    $blank = ['account_id' => '', 'stock_item_id' => '', 'description' => '', 'hsn_sac' => '', 'quantity' => '', 'item_rate' => '', 'taxable' => '', 'rate' => '18'];
    $rows = old('lines', $document->exists
        ? $document->lines->map(fn ($l) => [
            'account_id' => $l->account_id, 'stock_item_id' => $l->stock_item_id, 'description' => $l->description,
            'hsn_sac' => $l->hsn_sac, 'quantity' => $l->quantity !== null ? (float) $l->quantity : '',
            'item_rate' => $l->item_rate !== null ? (float) $l->item_rate : '', 'taxable' => (float) ($l->gross_amount ?? $l->taxable),
            'discount_percent' => (float) $l->discount_percent ?: '', 'rate' => (string) (float) $l->rate,
        ])->all()
        : [$blank]);
    $itemData = $items->mapWithKeys(fn ($a) => [$a->id => ['hsn' => $a->hsn_sac, 'rate' => $a->gst_rate === null ? null : (float) $a->gst_rate]]);
    $stockData = $stockItems->mapWithKeys(fn ($i) => [$i->id => ['hsn' => $i->hsn_sac, 'rate' => $i->gst_rate === null ? null : (float) $i->gst_rate, 'price' => (float) (\App\Models\BusinessDocument::TYPES[$type][1] === 'sales' && $i->selling_price !== null ? $i->selling_price : $i->opening_rate)]]);
@endphp

@section('title', ($document->exists ? 'Edit ' : 'New ').$label)

@section('content')
<div class="container-fluid py-3">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
        <div>
            <h2 class="h4 mb-1">{{ $document->exists ? 'Edit '.$label.' #'.$document->number : 'New '.$label }}</h2>
            <p class="text-secondary mb-0">{{ $company->name }} · doesn't affect the accounts or stock until converted{{ $type === 'delivery_challan' ? ' into an invoice' : '' }}</p>
        </div>
        <a href="{{ route('companies.documents.index', [$company, $type]) }}" class="btn btn-outline-secondary">Back</a>
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

    <form method="POST" action="{{ $document->exists ? route('companies.documents.update', [$company, $type, $document]) : route('companies.documents.store', [$company, $type]) }}">
        @csrf
        @if ($document->exists)
            @method('PUT')
        @endif

        <div class="card border-0 shadow-sm">
            <div class="card-body p-3 p-md-4">
                <div class="row g-3 mb-4">
                    <div class="col-12 col-md-4">
                        <label class="form-label" for="doc-party">Party *</label>
                        <select id="doc-party" name="party_account_id" class="form-select" required>
                            <option value="">Select party</option>
                            @foreach ($parties as $party)
                                <option value="{{ $party->id }}" @selected((string) old('party_account_id', $document->party_account_id) === (string) $party->id)>{{ $party->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-6 col-md-2">
                        <label class="form-label" for="doc-date">Date *</label>
                        <input id="doc-date" type="date" name="date" class="form-control" required value="{{ old('date', $document->date?->toDateString() ?? now()->toDateString()) }}">
                    </div>
                    <div class="col-6 col-md-2">
                        <label class="form-label" for="doc-valid">Valid until</label>
                        <input id="doc-valid" type="date" name="valid_until" class="form-control" value="{{ old('valid_until', $document->valid_until?->toDateString()) }}">
                    </div>
                    <div class="col-6 col-md-2">
                        <label class="form-label" for="doc-pos">Place of supply</label>
                        <select id="doc-pos" name="place_of_supply" class="form-select">
                            <option value="">From party</option>
                            @foreach ($states as $code => $name)
                                <option value="{{ $code }}" @selected(old('place_of_supply', $document->place_of_supply) === $code)>{{ $code }} - {{ $name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-6 col-md-2">
                        <label class="form-label" for="doc-ref">Reference</label>
                        <input id="doc-ref" name="reference" class="form-control" maxlength="255" value="{{ old('reference', $document->reference) }}">
                    </div>
                </div>

                @include('partials.barcode-scan')

                <div class="table-responsive">
                    <table class="table align-middle mb-2" id="lines">
                        <thead class="table-light">
                            <tr>
                                <th style="min-width: 180px">Ledger</th>
                                <th style="min-width: 160px">Stock item</th>
                                <th style="min-width: 140px">Description</th>
                                <th style="width: 110px">HSN/SAC</th>
                                <th style="width: 90px" class="text-end">Qty</th>
                                <th style="width: 110px" class="text-end">Rate</th>
                                <th style="width: 130px" class="text-end">Amount</th>
                                <th style="width: 90px" class="text-end">Disc %</th>
                                <th style="width: 90px">GST %</th>
                                <th style="width: 40px"></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($rows as $i => $row)
                                <tr>
                                    <td>
                                        <select name="lines[{{ $i }}][account_id]" class="form-select js-item" required aria-label="Ledger">
                                            <option value="">Select</option>
                                            @foreach ($items as $item)
                                                <option value="{{ $item->id }}" @selected((string) ($row['account_id'] ?? '') === (string) $item->id)>{{ $item->name }}</option>
                                            @endforeach
                                        </select>
                                    </td>
                                    <td>
                                        <select name="lines[{{ $i }}][stock_item_id]" class="form-select js-stock" aria-label="Stock item">
                                            <option value="">— none —</option>
                                            @foreach ($stockItems as $stockItem)
                                                <option value="{{ $stockItem->id }}" @selected((string) ($row['stock_item_id'] ?? '') === (string) $stockItem->id)>{{ $stockItem->name }}</option>
                                            @endforeach
                                        </select>
                                    </td>
                                    <td><input name="lines[{{ $i }}][description]" value="{{ $row['description'] ?? '' }}" class="form-control" maxlength="255" aria-label="Description"></td>
                                    <td><input name="lines[{{ $i }}][hsn_sac]" value="{{ $row['hsn_sac'] ?? '' }}" class="form-control js-hsn" maxlength="10" aria-label="HSN/SAC"></td>
                                    <td><input type="number" step="0.001" min="0" name="lines[{{ $i }}][quantity]" value="{{ $row['quantity'] ?? '' }}" class="form-control text-end js-qty" aria-label="Quantity"></td>
                                    <td><input type="number" step="0.01" min="0" name="lines[{{ $i }}][item_rate]" value="{{ $row['item_rate'] ?? '' }}" class="form-control text-end js-price" aria-label="Rate"></td>
                                    <td><input type="number" step="0.01" min="0" name="lines[{{ $i }}][taxable]" value="{{ $row['taxable'] ?? '' }}" class="form-control text-end js-taxable" aria-label="Amount"></td>
                                    <td><input type="number" step="0.01" min="0" max="100" name="lines[{{ $i }}][discount_percent]" value="{{ $row['discount_percent'] ?? '' }}" class="form-control text-end js-disc" aria-label="Discount %"></td>
                                    <td>
                                        <select name="lines[{{ $i }}][rate]" class="form-select js-rate" aria-label="GST rate">
                                            @foreach ($rates as $rate)
                                                <option value="{{ $rate }}" @selected((string) ($row['rate'] ?? '') === (string) $rate)>{{ $rate }}</option>
                                            @endforeach
                                        </select>
                                    </td>
                                    <td><button type="button" class="btn btn-sm btn-outline-danger js-remove" title="Remove line">&times;</button></td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot>
                            <tr class="fw-semibold">
                                <td colspan="6"><button type="button" class="btn btn-sm btn-outline-primary" id="add-line">+ Add line</button></td>
                                <td class="text-end" colspan="2">Taxable <span id="total-taxable">0.00</span></td>
                                <td colspan="2" class="text-end small text-secondary">+ GST <span id="total-tax">0.00</span></td>
                            </tr>
                            <tr class="fw-semibold">
                                <td colspan="8" class="text-end">Total</td>
                                <td colspan="2" class="text-end" id="grand-total">0.00</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                <label class="form-label" for="doc-notes">Notes / terms</label>
                <textarea id="doc-notes" name="notes" rows="2" maxlength="2000" class="form-control">{{ old('notes', $document->notes) }}</textarea>
            </div>
            <div class="card-footer bg-white text-end">
                <button class="btn btn-primary">Save {{ $label }}</button>
            </div>
        </div>
    </form>
</div>

<script>
(() => {
    const items = @json($itemData), stock = @json($stockData);
    // Price list of the chosen party, else the item's normal price.
    const priceLists = @json(\App\Http\Controllers\PriceListController::forForm($company));
    const listPrice = (itemId, fallback) => {
        const list = priceLists.parties[document.getElementById('doc-party').value];
        const price = list && priceLists.prices[list] ? priceLists.prices[list][itemId] : undefined;
        return price !== undefined ? price : fallback;
    };

    const body = document.querySelector('#lines tbody');
    let next = body.rows.length;
    const fmt = n => n.toFixed(2);

    function recalc() {
        let taxable = 0, tax = 0;
        for (const row of body.rows) {
            const hasItem = row.querySelector('.js-stock').value !== '';
            const t = row.querySelector('.js-taxable');
            t.readOnly = hasItem;
            if (hasItem) {
                const q = parseFloat(row.querySelector('.js-qty').value) || 0, p = parseFloat(row.querySelector('.js-price').value) || 0;
                t.value = q && p ? (Math.round(q * p * 100) / 100).toFixed(2) : '';
            }
            const disc = parseFloat(row.querySelector('.js-disc').value) || 0;
            const v = Math.round((parseFloat(t.value) || 0) * (1 - disc / 100) * 100) / 100, r = parseFloat(row.querySelector('.js-rate').value) || 0;
            taxable += v;
            tax += Math.round(v * r) / 100;
        }
        document.getElementById('total-taxable').textContent = fmt(taxable);
        document.getElementById('total-tax').textContent = fmt(tax);
        document.getElementById('grand-total').textContent = fmt(taxable + tax);
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
        if (e.target.classList.contains('js-remove') && body.rows.length > 1) { e.target.closest('tr').remove(); recalc(); }
    });
    body.addEventListener('change', e => {
        const row = e.target.closest('tr');
        const src = e.target.classList.contains('js-item') ? items[e.target.value] : e.target.classList.contains('js-stock') ? stock[e.target.value] : null;
        if (src) {
            if (src.hsn) row.querySelector('.js-hsn').value = src.hsn;
            if (src.rate !== null) row.querySelector('.js-rate').value = String(src.rate);
            const price = e.target.classList.contains('js-stock') ? listPrice(e.target.value, src.price) : src.price;
            if (price && !row.querySelector('.js-price').value) row.querySelector('.js-price').value = price;
        }
        recalc();
    });
    body.addEventListener('input', recalc);
    recalc();
})();
</script>
@endsection
