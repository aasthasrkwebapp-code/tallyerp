@extends('layouts.erp')

@section('title', $item->exists ? 'Edit Stock Item' : 'Create Stock Item')

@section('content')
<div class="container-fluid py-3">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
        <div>
            <h2 class="h4 mb-1">{{ $item->exists ? 'Edit Stock Item' : 'Create Stock Item' }}</h2>
            <p class="text-secondary mb-0">{{ $company->name }}</p>
        </div>
        <a href="{{ route('companies.stock-items.index', $company) }}" class="btn btn-outline-secondary">Back to Stock Items</a>
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
        action="{{ $item->exists ? route('companies.stock-items.update', [$company, $item]) : route('companies.stock-items.store', $company) }}">
        @csrf
        @if ($item->exists)
            @method('PUT')
        @endif

        <div class="card border-0 shadow-sm">
            <div class="card-body p-3 p-md-4">
                <div class="row g-3">
                    <div class="col-12 col-md-6">
                        <label class="form-label" for="f-name">Name *</label>
                        <input id="f-name" name="name" value="{{ old('name', $item->name) }}" class="form-control" required maxlength="255">
                    </div>
                    <div class="col-6 col-md-3">
                        <label class="form-label" for="f-unit-id">Unit *</label>
                        <select id="f-unit-id" name="unit_id" class="form-select" required>
                            @foreach ($units as $unit)
                                <option value="{{ $unit->id }}" @selected((string) old('unit_id', $item->unit_id) === (string) $unit->id)>
                                    {{ $unit->symbol }}{{ $unit->name ? ' - '.$unit->name : '' }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-6 col-md-3">
                        <label class="form-label" for="f-stock-group-id">Stock group</label>
                        <select id="f-stock-group-id" name="stock_group_id" class="form-select">
                            <option value="">Primary</option>
                            @foreach ($groups as $group)
                                <option value="{{ $group->id }}" @selected((string) old('stock_group_id', $item->stock_group_id) === (string) $group->id)>{{ $group->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-6 col-md-3">
                        <label class="form-label" for="f-hsn-sac">HSN / SAC</label>
                        <input id="f-hsn-sac" name="hsn_sac" value="{{ old('hsn_sac', $item->hsn_sac) }}" class="form-control" maxlength="10" inputmode="numeric">
                    </div>
                    <div class="col-6 col-md-3">
                        <label class="form-label" for="f-gst-rate">GST Rate %</label>
                        <select id="f-gst-rate" name="gst_rate" class="form-select">
                            <option value="">—</option>
                            @foreach (\App\Http\Controllers\GstInvoiceController::RATES as $rate)
                                <option value="{{ $rate }}"
                                    @selected((string) old('gst_rate', $item->gst_rate === null ? '' : (float) $item->gst_rate) === (string) $rate)>{{ $rate }}%</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-6 col-md-3">
                        <label class="form-label" for="f-opening-quantity">Opening quantity</label>
                        <input id="f-opening-quantity" type="number" step="0.001" min="0" name="opening_quantity"
                            value="{{ old('opening_quantity', (float) $item->opening_quantity) }}" class="form-control">
                    </div>
                    <div class="col-6 col-md-3">
                        <label class="form-label" for="f-opening-rate">Opening rate (cost)</label>
                        <input id="f-opening-rate" type="number" step="0.01" min="0" name="opening_rate"
                            value="{{ old('opening_rate', (float) $item->opening_rate) }}" class="form-control">
                    </div>
                    <div class="col-6 col-md-3">
                        <label class="form-label" for="f-selling-price">Selling price</label>
                        <input id="f-selling-price" type="number" step="0.01" min="0" name="selling_price"
                            value="{{ old('selling_price', $item->selling_price !== null ? (float) $item->selling_price : '') }}" class="form-control">
                    </div>
                    <div class="col-12 col-md-6">
                        <label class="form-label" for="f-barcode">Barcode</label>
                        <div class="input-group">
                            <input id="f-barcode" name="barcode" value="{{ old('barcode', $item->barcode) }}" class="form-control" maxlength="48" placeholder="Scan or type">
                            <button type="button" class="btn btn-outline-secondary"
                                onclick="document.getElementById('f-barcode').value = @js(\App\Http\Controllers\StockItemController::suggestBarcode($company))">Generate</button>
                        </div>
                        <div class="form-text">Scan the product's barcode, or generate one and print labels.</div>
                    </div>
                </div>
            </div>
            <div class="card-footer bg-white text-end">
                <button class="btn btn-primary">Save Item</button>
            </div>
        </div>
    </form>
</div>
@endsection
