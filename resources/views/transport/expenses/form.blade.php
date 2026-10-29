@extends('layouts.erp')

@section('title', 'New Transport Expense')

@section('content')
<div class="page-header">
    <div>
        <h1>New expense{{ $trip ? ' for trip '.$trip->number : '' }}</h1>
        <p class="text-secondary mb-0">Paid from cash or bank posts a payment voucher; from a driver advance or a supplier on credit, a journal.</p>
    </div>
    <a href="{{ $trip ? route('trips.show', $trip) : route('transport-expenses.index') }}" class="btn btn-outline-secondary">Back</a>
</div>

<form method="POST" action="{{ route('transport-expenses.store') }}" class="erp-card p-3 p-md-4" style="max-width: 860px">
    @csrf
    <div class="row g-3">
        <div class="col-12 col-md-6">
            <label for="trip_id" class="form-label">Trip <small class="text-secondary">(leave empty for a vehicle expense such as a repair)</small></label>
            <select id="trip_id" name="trip_id" class="form-select @error('trip_id') is-invalid @enderror">
                <option value="">— No trip</option>
                @foreach ($trips as $t)<option value="{{ $t->id }}" data-vehicle="{{ $t->vehicle_id }}" @selected((int) old('trip_id', $trip?->id) === $t->id)>{{ $t->number }} · {{ $t->vehicle->number }} · {{ $t->origin }} – {{ $t->destination }}</option>@endforeach
            </select>
            @error('trip_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <div class="col-12 col-md-6">
            <label for="vehicle_id" class="form-label">Vehicle</label>
            <select id="vehicle_id" name="vehicle_id" class="form-select @error('vehicle_id') is-invalid @enderror">
                <option value="">Choose…</option>
                @foreach ($vehicles as $v)<option value="{{ $v->id }}" @selected((int) old('vehicle_id', $vehicleId) === $v->id)>{{ $v->number }}</option>@endforeach
            </select>
            @error('vehicle_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <div class="col-6 col-md-4">
            <label for="date" class="form-label">Date</label>
            <input type="date" id="date" name="date" value="{{ old('date', now()->toDateString()) }}" class="form-control @error('date') is-invalid @enderror" required>
            @error('date')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <div class="col-6 col-md-4">
            <label for="category" class="form-label">Type</label>
            <select id="category" name="category" class="form-select @error('category') is-invalid @enderror">
                @foreach (config('erp.transport_expense_categories') as $k => $c)<option value="{{ $k }}" @selected(old('category', 'fuel') === $k)>{{ $c['label'] }}</option>@endforeach
            </select>
        </div>
        <div class="col-6 col-md-2">
            <label for="quantity" class="form-label">Litres</label>
            <input id="quantity" name="quantity" value="{{ old('quantity') }}" class="form-control text-end @error('quantity') is-invalid @enderror" inputmode="decimal" placeholder="Fuel">
        </div>
        <div class="col-6 col-md-2">
            <label for="amount" class="form-label">Amount</label>
            <input id="amount" name="amount" value="{{ old('amount') }}" class="form-control text-end @error('amount') is-invalid @enderror" inputmode="decimal" required>
            @error('amount')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <div class="col-12 col-md-6">
            <label for="paid_account_id" class="form-label">Paid from</label>
            <select id="paid_account_id" name="paid_account_id" class="form-select @error('paid_account_id') is-invalid @enderror" required>
                <option value="">Choose…</option>
                @foreach ($paidFrom as $a)<option value="{{ $a->id }}" @selected((int) old('paid_account_id', $defaultPaid) === $a->id)>{{ $a->name }}</option>@endforeach
            </select>
            @error('paid_account_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <div class="col-12 col-md-6">
            <label for="expense_account_id" class="form-label">Expense ledger</label>
            <select id="expense_account_id" name="expense_account_id" class="form-select @error('expense_account_id') is-invalid @enderror">
                <option value="">Default for the type</option>
                @foreach ($expenseLedgers as $a)<option value="{{ $a->id }}" @selected((int) old('expense_account_id') === $a->id)>{{ $a->name }}</option>@endforeach
            </select>
        </div>
        <div class="col-12">
            <label for="description" class="form-label">Description</label>
            <input id="description" name="description" value="{{ old('description') }}" class="form-control" maxlength="255" placeholder="E.g. pump name, toll plaza, workshop">
        </div>
    </div>
    <div class="d-flex flex-wrap gap-2 mt-4">
        <button class="btn btn-primary">Save expense</button>
        <button class="btn btn-outline-primary" name="another" value="1">Save and add another</button>
        <a href="{{ $trip ? route('trips.show', $trip) : route('transport-expenses.index') }}" class="btn btn-outline-secondary">Cancel</a>
    </div>
</form>
@endsection

@push('scripts')
<script>
document.getElementById('trip_id').addEventListener('change', function () {
    var opt = this.options[this.selectedIndex];
    if (opt.dataset.vehicle) document.getElementById('vehicle_id').value = opt.dataset.vehicle;
});
</script>
@endpush
