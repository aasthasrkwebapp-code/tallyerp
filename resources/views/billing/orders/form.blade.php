@extends('layouts.erp')

@php($partyWord = $config['party_group'] === 'sundry_debtors' ? 'Customer' : 'Supplier')

@section('title', ($order->exists ? 'Alter ' : 'New ').$config['label'])

@section('content')
<div class="page-header">
    <div>
        <h1>{{ $order->exists ? 'Alter '.$config['label'].' '.$order->number : 'New '.mb_strtolower($config['label']) }}</h1>
        <p class="text-secondary mb-0">GST is worked out on saving from the {{ $config['invoice_kind'] === 'sales' ? 'place of supply' : "supplier's state" }}.</p>
    </div>
    <a href="{{ $order->exists ? route('orders.show', $order) : route('orders.index', ['kind' => $kind]) }}" class="btn btn-outline-secondary">Back</a>
</div>

@error('order')<div class="alert alert-danger">{{ $message }}</div>@enderror

<form method="POST" action="{{ $order->exists ? route('orders.update', $order) : route('orders.store') }}" id="order-form" novalidate>
    @csrf
    @if ($order->exists) @method('PUT') @endif
    <input type="hidden" name="kind" value="{{ $kind }}">

    <div class="erp-card p-3 mb-3">
        <div class="row g-3">
            <div class="col-12 col-md-6 col-lg-4">
                <label for="party_account_id" class="form-label">{{ $partyWord }} <span class="text-danger">*</span></label>
                <select id="party_account_id" name="party_account_id" class="form-select @error('party_account_id') is-invalid @enderror" required>
                    <option value="">Select</option>
                    @foreach ($parties as $p)<option value="{{ $p->id }}" @selected((int) ($input['party_account_id'] ?? 0) === $p->id)>{{ $p->name }}</option>@endforeach
                </select>
                @error('party_account_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="col-6 col-md-3 col-lg-2">
                <label for="date" class="form-label">{{ ($config['quotation'] ?? false) ? 'Date' : 'Order date' }}</label>
                <input id="date" type="date" name="date" value="{{ $input['date'] ?? '' }}" class="form-control @error('date') is-invalid @enderror" required>
                @error('date')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="col-6 col-md-3 col-lg-2">
                <label for="delivery_date" class="form-label">{{ $config['date_label'] ?? 'Delivery by' }}</label>
                <input id="delivery_date" type="date" name="delivery_date" value="{{ $input['delivery_date'] ?? '' }}" class="form-control @error('delivery_date') is-invalid @enderror">
                @error('delivery_date')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="col-6 col-md-3 col-lg-2">
                <label for="reference" class="form-label">{{ ($config['quotation'] ?? false) ? 'Enquiry ref.' : ($config['invoice_kind'] === 'sales' ? "Customer's PO no." : 'Quotation ref.') }}</label>
                <input id="reference" name="reference" value="{{ $input['reference'] ?? '' }}" class="form-control @error('reference') is-invalid @enderror" maxlength="50">
            </div>
            <div class="col-6 col-md-3 col-lg-2">
                <label for="reference_date" class="form-label">Reference date</label>
                <input id="reference_date" type="date" name="reference_date" value="{{ $input['reference_date'] ?? '' }}" class="form-control">
            </div>
            @if ($config['invoice_kind'] === 'sales')
                <div class="col-12 col-md-6 col-lg-3">
                    <label for="place_of_supply" class="form-label">Place of supply</label>
                    <select id="place_of_supply" name="place_of_supply" class="form-select @error('place_of_supply') is-invalid @enderror">
                        <option value="">From customer</option>
                        @foreach ($states as $s)<option value="{{ $s }}" @selected(($input['place_of_supply'] ?? '') === $s)>{{ $s }}</option>@endforeach
                    </select>
                </div>
            @endif
        </div>
    </div>

    <div class="erp-card mb-3">
        <div class="erp-card-header d-flex justify-content-between align-items-center">
            <span>Items</span>
            <button type="button" class="btn btn-sm btn-outline-primary" id="add-item" data-shortcut="a"><i class="bi bi-plus-lg"></i> Add item</button>
        </div>
        @error('items')<div class="alert alert-danger m-3 mb-0 small">{{ $message }}</div>@enderror
        <div id="order-items" class="voucher-lines p-2 p-md-3">
            @php($rows = array_values($input['items'] ?? []))
            @if ($rows === [])@php($rows = [[]])@endif
            @foreach ($rows as $i => $item)
                @include('billing.orders._item', ['i' => $i, 'item' => $item])
            @endforeach
        </div>
        <div class="border-top p-3 d-flex justify-content-end gap-3 small">
            <span class="text-secondary">Value before tax</span><strong id="order-total">0.00</strong>
        </div>
    </div>

    <div class="erp-card p-3 mb-3">
        <div class="row g-3">
            <div class="col-12 col-md-6">
                <label for="narration" class="form-label">Narration</label>
                <textarea id="narration" name="narration" rows="2" class="form-control" maxlength="2000">{{ $input['narration'] ?? '' }}</textarea>
            </div>
            <div class="col-12 col-md-6">
                <label for="terms" class="form-label">Terms &amp; conditions</label>
                <textarea id="terms" name="terms" rows="2" class="form-control" maxlength="2000">{{ $input['terms'] ?? '' }}</textarea>
            </div>
        </div>
    </div>

    <div class="d-flex flex-wrap gap-2 mb-5">
        <button type="submit" class="btn btn-primary" data-shortcut="s">Save {{ mb_strtolower($config['label']) }}</button>
        <a href="{{ $order->exists ? route('orders.show', $order) : route('orders.index', ['kind' => $kind]) }}" class="btn btn-outline-secondary">Cancel</a>
    </div>
</form>

<template id="item-template">
    @include('billing.orders._item', ['i' => '__I__', 'item' => []])
</template>
@endsection

@push('scripts')
<script>
(function () {
    var stockItems = @json($stockItems);
    var box = document.getElementById('order-items');
    var template = document.getElementById('item-template');
    var next = box.querySelectorAll('.order-item').length;

    function num(v) { var n = parseFloat(String(v || '').replace(/,/g, '')); return isFinite(n) ? n : 0; }
    function recalc() {
        var total = 0;
        box.querySelectorAll('.order-item').forEach(function (row) {
            var gross = num(row.querySelector('.item-qty').value) * num(row.querySelector('.item-rate').value);
            var value = gross - gross * num(row.querySelector('.item-disc').value) / 100;
            row.querySelector('.item-amount').value = value.toFixed(2);
            total += value;
        });
        document.getElementById('order-total').textContent = total.toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }
    function bind(row) {
        row.querySelector('.item-remove').addEventListener('click', function () {
            if (box.querySelectorAll('.order-item').length > 1) row.remove();
            recalc();
        });
        var stock = row.querySelector('.item-stock');
        stock.addEventListener('change', function () {
            var id = parseInt(stock.value, 10);
            var s = stockItems.find(function (x) { return x.id === id; });
            if (!s) return;
            var set = function (sel, v) { var el = row.querySelector(sel); if (el && v !== null && v !== undefined && v !== '') el.value = v; };
            set('.item-desc', s.name); set('.item-unit', s.unit); set('.item-hsn', s.hsn); set('.item-gst', s.gst); set('.item-rate', s.rate);
            recalc();
        });
    }
    document.getElementById('add-item').addEventListener('click', function () {
        var wrap = document.createElement('div');
        wrap.innerHTML = template.innerHTML.replace(/__I__/g, String(next++)).trim();
        var row = wrap.firstElementChild;
        box.appendChild(row);
        bind(row);
        row.querySelector('.item-stock').focus();
        recalc();
    });
    box.querySelectorAll('.order-item').forEach(bind);
    box.addEventListener('input', recalc);
    box.addEventListener('change', recalc);
    recalc();
})();
</script>
@endpush
