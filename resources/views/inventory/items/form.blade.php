@extends('layouts.erp')

@section('title', $item->exists ? 'Edit Stock Item' : 'New Stock Item')

@php($v = fn ($name, $default = null) => old($name, $item->{$name} ?? $default))
@php($dec = fn ($value) => $value === null ? '' : \App\Support\Decimal::trim($value))

@section('content')
<div class="page-header">
    <h1>{{ $item->exists ? 'Edit stock item: '.$item->name : 'New stock item' }}</h1>
    <a href="{{ $item->exists ? route('stock-items.show', $item) : route('stock-items.index') }}" class="btn btn-outline-secondary">Back</a>
</div>

<form method="POST" action="{{ $item->exists ? route('stock-items.update', $item) : route('stock-items.store') }}" class="erp-card p-3 p-md-4" style="max-width: 960px">
    @csrf
    @if ($item->exists) @method('PUT') @endif

    <div class="row g-3">
        <div class="col-12 col-md-6">
            <label for="name" class="form-label">Name</label>
            <input id="name" name="name" value="{{ $v('name') }}" class="form-control @error('name') is-invalid @enderror" required maxlength="150" autofocus>
            @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <div class="col-6 col-md-3">
            <label for="alias" class="form-label">Alias</label>
            <input id="alias" name="alias" value="{{ $v('alias') }}" class="form-control" maxlength="150">
        </div>
        <div class="col-6 col-md-3">
            <label for="code" class="form-label">Code / SKU</label>
            <input id="code" name="code" value="{{ $v('code') }}" class="form-control @error('code') is-invalid @enderror" maxlength="50">
            @error('code')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>

        <div class="col-6 col-md-4">
            <label for="stock_group_id" class="form-label">Group</label>
            <select id="stock_group_id" name="stock_group_id" class="form-select">
                <option value="">Primary</option>
                @foreach ($groups as $g)<option value="{{ $g->id }}" @selected((int) $v('stock_group_id') === $g->id)>{{ str_repeat('— ', $g->depth) }}{{ $g->name }}</option>@endforeach
            </select>
        </div>
        <div class="col-6 col-md-4">
            <label for="stock_category_id" class="form-label">Category</label>
            <select id="stock_category_id" name="stock_category_id" class="form-select">
                <option value="">None</option>
                @foreach ($categories as $c)<option value="{{ $c->id }}" @selected((int) $v('stock_category_id') === $c->id)>{{ $c->name }}</option>@endforeach
            </select>
        </div>
        <div class="col-12 col-md-4">
            <label for="unit_id" class="form-label">Unit</label>
            <select id="unit_id" name="unit_id" class="form-select @error('unit_id') is-invalid @enderror" required>
                @foreach ($units as $u)<option value="{{ $u->id }}" data-decimals="{{ $u->decimal_places }}" @selected((int) $v('unit_id') === $u->id)>{{ $u->symbol }} ({{ $u->name }})</option>@endforeach
            </select>
            @error('unit_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>

        <div class="col-12"><h2 class="h6 fw-bold mb-0 mt-2">Invoice defaults</h2></div>
        <div class="col-6 col-md-3">
            <label for="hsn_sac" class="form-label">HSN / SAC</label>
            <input id="hsn_sac" name="hsn_sac" value="{{ $v('hsn_sac') }}" class="form-control @error('hsn_sac') is-invalid @enderror" inputmode="numeric" maxlength="8">
            @error('hsn_sac')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <div class="col-6 col-md-3">
            <label for="gst_rate" class="form-label">GST rate</label>
            <select id="gst_rate" name="gst_rate" class="form-select @error('gst_rate') is-invalid @enderror">
                <option value="">Not set</option>
                @foreach (config('erp.gst_rates') as $rate)<option value="{{ $rate }}" @selected($dec($v('gst_rate')) === $rate && $v('gst_rate') !== null)>{{ $rate }}%</option>@endforeach
            </select>
        </div>
        <div class="col-6 col-md-3">
            <label for="cess_rate" class="form-label">Cess rate % <span class="text-secondary small">(optional)</span></label>
            <input id="cess_rate" name="cess_rate" value="{{ $v('cess_rate') !== null && (float) $v('cess_rate') ? $dec($v('cess_rate')) : '' }}" class="form-control text-end @error('cess_rate') is-invalid @enderror" inputmode="decimal" placeholder="E.g. 12 for aerated drinks">
            @error('cess_rate')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <div class="col-6 col-md-3">
            <label for="sales_rate" class="form-label">Selling rate</label>
            <input id="sales_rate" name="sales_rate" value="{{ $v('sales_rate') }}" class="form-control text-end @error('sales_rate') is-invalid @enderror" inputmode="decimal">
            @error('sales_rate')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <div class="col-6 col-md-3">
            <label for="purchase_rate" class="form-label">Purchase rate</label>
            <input id="purchase_rate" name="purchase_rate" value="{{ $v('purchase_rate') }}" class="form-control text-end @error('purchase_rate') is-invalid @enderror" inputmode="decimal">
            @error('purchase_rate')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <div class="col-12 col-md-6">
            <label for="sales_account_id" class="form-label">Sales ledger</label>
            <select id="sales_account_id" name="sales_account_id" class="form-select @error('sales_account_id') is-invalid @enderror">
                <option value="">Choose on each invoice</option>
                @foreach ($salesLedgers as $a)<option value="{{ $a->id }}" @selected((int) $v('sales_account_id') === $a->id)>{{ $a->name }}</option>@endforeach
            </select>
            @error('sales_account_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <div class="col-12 col-md-6">
            <label for="purchase_account_id" class="form-label">Purchase ledger</label>
            <select id="purchase_account_id" name="purchase_account_id" class="form-select @error('purchase_account_id') is-invalid @enderror">
                <option value="">Choose on each bill</option>
                @foreach ($purchaseLedgers as $a)<option value="{{ $a->id }}" @selected((int) $v('purchase_account_id') === $a->id)>{{ $a->name }}</option>@endforeach
            </select>
            @error('purchase_account_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>

        @php($locked = $item->exists && $item->hasMovements())
        @php($dateVal = fn ($name) => old($name, $item->{$name}?->toDateString()))
        <div class="col-12"><h2 class="h6 fw-bold mb-0 mt-2">Batches and serial numbers</h2></div>
        <div class="col-12 col-md-6">
            <label for="tracking" class="form-label">Track this item by</label>
            <select id="tracking" name="tracking" class="form-select @error('tracking') is-invalid @enderror" @disabled($locked)>
                @foreach (\App\Models\StockItem::TRACKING as $key => $label)<option value="{{ $key }}" @selected($v('tracking', 'none') === $key)>{{ $label }}</option>@endforeach
            </select>
            @if ($locked)<input type="hidden" name="tracking" value="{{ $item->tracking }}"><div class="form-text">Fixed: documents already move this item.</div>@endif
            @error('tracking')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <div class="col-12 col-md-6 d-flex align-items-end batch-only">
            <div class="form-check mb-2">
                <input type="hidden" name="track_expiry" value="0">
                <input class="form-check-input" type="checkbox" name="track_expiry" value="1" id="track_expiry" @checked($v('track_expiry'))>
                <label class="form-check-label" for="track_expiry">Batches have expiry dates (required on purchases)</label>
            </div>
        </div>

        <div class="col-12"><h2 class="h6 fw-bold mb-0 mt-2">Opening stock <small class="text-secondary fw-normal">(as at the books beginning date)</small></h2></div>
        <div class="col-6 col-md-3">
            <label for="opening_quantity" class="form-label">Quantity</label>
            <input id="opening_quantity" name="opening_quantity" value="{{ old('opening_quantity', $dec($item->opening_quantity)) }}" class="form-control text-end @error('opening_quantity') is-invalid @enderror" inputmode="decimal">
            @error('opening_quantity')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <div class="col-6 col-md-3">
            <label for="opening_rate" class="form-label">Rate</label>
            <input id="opening_rate" name="opening_rate" value="{{ old('opening_rate') }}" class="form-control text-end @error('opening_rate') is-invalid @enderror" inputmode="decimal" placeholder="Optional">
            @error('opening_rate')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <div class="col-6 col-md-3">
            <label for="opening_value" class="form-label">Value</label>
            <input id="opening_value" name="opening_value" value="{{ old('opening_value', $item->exists ? $item->opening_value : '') }}" class="form-control text-end @error('opening_value') is-invalid @enderror" inputmode="decimal" placeholder="Quantity × rate">
            @error('opening_value')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <div class="col-6 col-md-3">
            <label for="opening_godown_id" class="form-label">Godown</label>
            <select id="opening_godown_id" name="opening_godown_id" class="form-select">
                @foreach ($godowns as $g)<option value="{{ $g->id }}" @selected((int) $v('opening_godown_id') === $g->id)>{{ $g->name }}</option>@endforeach
            </select>
        </div>

        <div class="col-12 col-md-4 batch-only">
            <label for="opening_batch_no" class="form-label">Opening batch no.</label>
            <input id="opening_batch_no" name="opening_batch_no" value="{{ $v('opening_batch_no') }}" class="form-control @error('opening_batch_no') is-invalid @enderror" maxlength="50" @readonly($locked)>
            @error('opening_batch_no')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <div class="col-6 col-md-4 batch-only">
            <label for="opening_mfg_date" class="form-label">Mfg date</label>
            <input type="date" id="opening_mfg_date" name="opening_mfg_date" value="{{ $dateVal('opening_mfg_date') }}" class="form-control @error('opening_mfg_date') is-invalid @enderror" @readonly($locked)>
            @error('opening_mfg_date')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <div class="col-6 col-md-4 batch-only">
            <label for="opening_expiry_date" class="form-label">Expiry date</label>
            <input type="date" id="opening_expiry_date" name="opening_expiry_date" value="{{ $dateVal('opening_expiry_date') }}" class="form-control @error('opening_expiry_date') is-invalid @enderror" @readonly($locked)>
            @error('opening_expiry_date')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <div class="col-12 serial-only">
            <label for="opening_serials" class="form-label">Opening serial numbers <span class="text-secondary">(one per unit; one per line or comma separated)</span></label>
            <textarea id="opening_serials" name="opening_serials" rows="2" class="form-control @error('opening_serials') is-invalid @enderror" @readonly($locked)>{{ $v('opening_serials') }}</textarea>
            @error('opening_serials')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>

        <div class="col-6 col-md-3">
            <label for="reorder_level" class="form-label">Reorder level</label>
            <input id="reorder_level" name="reorder_level" value="{{ old('reorder_level', $dec($item->reorder_level)) }}" class="form-control text-end @error('reorder_level') is-invalid @enderror" inputmode="decimal" placeholder="Optional">
            @error('reorder_level')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <div class="col-6 col-md-3 d-flex align-items-end">
            <div class="form-check mb-2">
                <input type="hidden" name="is_active" value="0">
                <input class="form-check-input" type="checkbox" name="is_active" value="1" id="is_active" @checked($v('is_active', true))>
                <label class="form-check-label" for="is_active">Active</label>
            </div>
        </div>
        <div class="col-12">
            <label for="description" class="form-label">Description</label>
            <textarea id="description" name="description" rows="2" class="form-control" maxlength="2000">{{ $v('description') }}</textarea>
        </div>
    </div>

    <div class="d-flex flex-wrap gap-2 mt-4">
        <button class="btn btn-primary">{{ $item->exists ? 'Save changes' : 'Create item' }}</button>
        <a href="{{ $item->exists ? route('stock-items.show', $item) : route('stock-items.index') }}" class="btn btn-outline-secondary">Cancel</a>
    </div>
</form>
@endsection

@push('scripts')
<script>
(function () {
    var qty = document.getElementById('opening_quantity');
    var rate = document.getElementById('opening_rate');
    var value = document.getElementById('opening_value');
    // Fill the value from quantity × rate while the rate is being used.
    function fill() {
        if (rate.value.trim() === '') return;
        var v = parseFloat(qty.value || '0') * parseFloat(rate.value || '0');
        if (isFinite(v)) value.value = v.toFixed(2);
    }
    qty.addEventListener('input', fill);
    rate.addEventListener('input', fill);

    var tracking = document.getElementById('tracking');
    function track() {
        document.querySelectorAll('.batch-only').forEach(function (el) { el.hidden = tracking.value !== 'batch'; });
        document.querySelectorAll('.serial-only').forEach(function (el) { el.hidden = tracking.value !== 'serial'; });
    }
    tracking.addEventListener('change', track);
    track();
})();
</script>
@endpush
