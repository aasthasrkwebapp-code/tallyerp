@extends('layouts.erp')

@section('title', $trip->exists ? 'Edit Trip' : 'New Trip')

@php($v = fn ($name, $default = null) => old($name, $trip->{$name} instanceof \DateTimeInterface ? $trip->{$name}->toDateString() : ($trip->{$name} ?? $default)))

@section('content')
<div class="page-header">
    <h1>{{ $trip->exists ? 'Edit trip '.$trip->number : 'New trip' }}</h1>
    <a href="{{ $trip->exists ? route('trips.show', $trip) : route('trips.index') }}" class="btn btn-outline-secondary">Back</a>
</div>

@if ($vehicles->isEmpty())
    <div class="alert alert-warning">Add a vehicle first under <a href="{{ route('transport-masters.index', 'vehicles') }}">Vehicles</a>.</div>
@endif

<form method="POST" action="{{ $trip->exists ? route('trips.update', $trip) : route('trips.store') }}" class="erp-card p-3 p-md-4" style="max-width: 960px">
    @csrf
    @if ($trip->exists) @method('PUT') @endif
    <div class="row g-3">
        <div class="col-6 col-md-4">
            <label for="vehicle_id" class="form-label">Vehicle</label>
            <select id="vehicle_id" name="vehicle_id" class="form-select @error('vehicle_id') is-invalid @enderror" required>
                <option value="">Choose…</option>
                @foreach ($vehicles as $x)<option value="{{ $x->id }}" @selected((int) $v('vehicle_id') === $x->id)>{{ $x->number }}{{ $x->ownership === 'hired' ? ' (hired)' : '' }}</option>@endforeach
            </select>
            @error('vehicle_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <div class="col-6 col-md-4">
            <label for="driver_id" class="form-label">Driver</label>
            <select id="driver_id" name="driver_id" class="form-select">
                <option value="">—</option>
                @foreach ($drivers as $x)<option value="{{ $x->id }}" @selected((int) $v('driver_id') === $x->id)>{{ $x->name }}</option>@endforeach
            </select>
        </div>
        <div class="col-12 col-md-4">
            <label for="start_date" class="form-label">Start date</label>
            <input type="date" id="start_date" name="start_date" value="{{ $v('start_date') }}" class="form-control @error('start_date') is-invalid @enderror" required>
            @error('start_date')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <div class="col-12 col-md-4">
            <label for="transport_route_id" class="form-label">Route</label>
            <select id="transport_route_id" name="transport_route_id" class="form-select">
                <option value="">— (enter places)</option>
                @foreach ($routes as $x)<option value="{{ $x->id }}" data-origin="{{ $x->origin }}" data-destination="{{ $x->destination }}" @selected((int) $v('transport_route_id') === $x->id)>{{ $x->name }}</option>@endforeach
            </select>
        </div>
        <div class="col-6 col-md-4">
            <label for="origin" class="form-label">From</label>
            <input id="origin" name="origin" value="{{ $v('origin') }}" class="form-control @error('origin') is-invalid @enderror" maxlength="100">
            @error('origin')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <div class="col-6 col-md-4">
            <label for="destination" class="form-label">To</label>
            <input id="destination" name="destination" value="{{ $v('destination') }}" class="form-control" maxlength="100">
        </div>
        <div class="col-12 col-md-6">
            <label for="party_account_id" class="form-label">Customer (billed for freight)</label>
            <select id="party_account_id" name="party_account_id" class="form-select @error('party_account_id') is-invalid @enderror">
                <option value="">—</option>
                @foreach ($customers as $x)<option value="{{ $x->id }}" @selected((int) $v('party_account_id') === $x->id)>{{ $x->name }}</option>@endforeach
            </select>
            @error('party_account_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <div class="col-6 col-md-3">
            <label for="freight_amount" class="form-label">Agreed freight</label>
            <input id="freight_amount" name="freight_amount" value="{{ $v('freight_amount') }}" class="form-control text-end @error('freight_amount') is-invalid @enderror" inputmode="decimal">
            @error('freight_amount')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <div class="col-6 col-md-3">
            <label for="lr_number" class="form-label">LR / consignment no.</label>
            <input id="lr_number" name="lr_number" value="{{ $v('lr_number') }}" class="form-control" maxlength="50">
            @unless ($trip->exists)
                <div class="form-check small mt-1">
                    <input type="hidden" name="auto_lr" value="0">
                    <input class="form-check-input" type="checkbox" name="auto_lr" value="1" id="auto_lr" @checked(old('auto_lr', '1') === '1')>
                    <label class="form-check-label" for="auto_lr">If blank, give the next LR number</label>
                </div>
            @endunless
        </div>
        <div class="col-12 col-md-6">
            <label for="material" class="form-label">Material</label>
            <input id="material" name="material" value="{{ $v('material') }}" class="form-control" maxlength="150">
        </div>
        <div class="col-6 col-md-3">
            <label for="weight_tonnes" class="form-label">Weight (tonnes)</label>
            <input id="weight_tonnes" name="weight_tonnes" value="{{ $v('weight_tonnes') !== null ? \App\Support\Decimal::trim($v('weight_tonnes')) : '' }}" class="form-control text-end @error('weight_tonnes') is-invalid @enderror" inputmode="decimal">
        </div>
        <div class="col-6 col-md-3">
            <label for="start_km" class="form-label">Odometer at start</label>
            <input id="start_km" name="start_km" value="{{ $v('start_km') }}" class="form-control text-end @error('start_km') is-invalid @enderror" inputmode="numeric">
            @error('start_km')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <fieldset class="col-12">
            <legend class="h6 fw-bold text-secondary mt-2">Lorry receipt and e-way bill</legend>
            <div class="row g-3">
                <div class="col-6 col-md-3">
                    <label for="lr_date" class="form-label">LR date</label>
                    <input type="date" id="lr_date" name="lr_date" value="{{ $v('lr_date') }}" class="form-control @error('lr_date') is-invalid @enderror">
                </div>
                <div class="col-6 col-md-3">
                    <label for="freight_terms" class="form-label">Freight terms</label>
                    <select id="freight_terms" name="freight_terms" class="form-select">
                        @foreach (\App\Models\Trip::FREIGHT_TERMS as $k => $label)<option value="{{ $k }}" @selected($v('freight_terms', 'to_be_billed') === $k)>{{ $label }}</option>@endforeach
                    </select>
                </div>
                <div class="col-6 col-md-3">
                    <label for="eway_bill_no" class="form-label">E-way bill no.</label>
                    <input id="eway_bill_no" name="eway_bill_no" value="{{ $v('eway_bill_no') }}" class="form-control @error('eway_bill_no') is-invalid @enderror" inputmode="numeric" maxlength="14" placeholder="12 digits">
                    @error('eway_bill_no')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-6 col-md-3">
                    <label for="eway_bill_valid_till" class="form-label">Valid till</label>
                    <input type="date" id="eway_bill_valid_till" name="eway_bill_valid_till" value="{{ $v('eway_bill_valid_till') }}" class="form-control @error('eway_bill_valid_till') is-invalid @enderror">
                    @error('eway_bill_valid_till')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-12 col-md-6">
                    <label for="consignor_name" class="form-label">Consignor</label>
                    <input id="consignor_name" name="consignor_name" value="{{ $v('consignor_name') }}" class="form-control mb-2" maxlength="150" placeholder="Name">
                    <textarea id="consignor_address" name="consignor_address" rows="2" class="form-control mb-2" maxlength="500" placeholder="Address" aria-label="Consignor address">{{ $v('consignor_address') }}</textarea>
                    <input id="consignor_gstin" name="consignor_gstin" value="{{ $v('consignor_gstin') }}" class="form-control text-uppercase @error('consignor_gstin') is-invalid @enderror" maxlength="15" placeholder="GSTIN" aria-label="Consignor GSTIN">
                    @error('consignor_gstin')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    <div class="form-text">Leave blank to use the customer.</div>
                </div>
                <div class="col-12 col-md-6">
                    <label for="consignee_name" class="form-label">Consignee</label>
                    <input id="consignee_name" name="consignee_name" value="{{ $v('consignee_name') }}" class="form-control mb-2" maxlength="150" placeholder="Name">
                    <textarea id="consignee_address" name="consignee_address" rows="2" class="form-control mb-2" maxlength="500" placeholder="Address" aria-label="Consignee address">{{ $v('consignee_address') }}</textarea>
                    <input id="consignee_gstin" name="consignee_gstin" value="{{ $v('consignee_gstin') }}" class="form-control text-uppercase @error('consignee_gstin') is-invalid @enderror" maxlength="15" placeholder="GSTIN" aria-label="Consignee GSTIN">
                    @error('consignee_gstin')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    
                </div>
                <div class="col-6 col-md-3">
                    <label for="packages" class="form-label">Packages</label>
                    <input id="packages" name="packages" value="{{ $v('packages') }}" class="form-control text-end @error('packages') is-invalid @enderror" inputmode="numeric">
                </div>
                <div class="col-6 col-md-3">
                    <label for="packing" class="form-label">Packing</label>
                    <input id="packing" name="packing" value="{{ $v('packing') }}" class="form-control" maxlength="50" placeholder="Bags, boxes">
                </div>
                <div class="col-6 col-md-3">
                    <label for="goods_invoice_no" class="form-label">Goods invoice no.</label>
                    <input id="goods_invoice_no" name="goods_invoice_no" value="{{ $v('goods_invoice_no') }}" class="form-control" maxlength="50">
                </div>
                <div class="col-6 col-md-3">
                    <label for="goods_value" class="form-label">Value of goods</label>
                    <input id="goods_value" name="goods_value" value="{{ $v('goods_value') }}" class="form-control text-end @error('goods_value') is-invalid @enderror" inputmode="decimal">
                    @error('goods_value')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
            </div>
        </fieldset>
        <div class="col-12">
            <label for="narration" class="form-label">Notes</label>
            <textarea id="narration" name="narration" rows="2" class="form-control" maxlength="2000">{{ $v('narration') }}</textarea>
        </div>
    </div>
    <div class="d-flex flex-wrap gap-2 mt-4">
        <button class="btn btn-primary">{{ $trip->exists ? 'Save changes' : 'Create trip' }}</button>
        <a href="{{ $trip->exists ? route('trips.show', $trip) : route('trips.index') }}" class="btn btn-outline-secondary">Cancel</a>
    </div>
</form>
@endsection

@push('scripts')
<script>
document.getElementById('transport_route_id').addEventListener('change', function () {
    var opt = this.options[this.selectedIndex];
    if (!opt.value) return;
    document.getElementById('origin').value = opt.dataset.origin;
    document.getElementById('destination').value = opt.dataset.destination;
});
</script>
@endpush
