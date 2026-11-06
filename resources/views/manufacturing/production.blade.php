@extends('layouts.erp')

@section('title', 'Record Production')

@section('content')
@php
    $q = fn ($v) => \App\Http\Controllers\ManufacturingController::qty((float) $v);
    $recipes = $boms->mapWithKeys(fn ($b) => [$b->id => [
        'out' => (float) $b->output_quantity,
        'unit' => $b->stockItem->unit->symbol,
        'components' => $b->components->map(fn ($c) => ['name' => $c->stockItem->name, 'unit' => $c->stockItem->unit->symbol, 'qty' => (float) $c->quantity])->values(),
    ]]);
@endphp
<div class="container-fluid py-3">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
        <div>
            <h2 class="h4 mb-1">Record Production</h2>
            <p class="text-secondary mb-0">{{ $company->name }}</p>
        </div>
        <a href="{{ route('companies.manufacturing.index', $company) }}" class="btn btn-outline-secondary">Back</a>
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

    <form method="POST" action="{{ route('companies.manufacturing.production.store', $company) }}">
        @csrf
        <div class="card border-0 shadow-sm">
            <div class="card-body p-3 p-md-4">
                <div class="row g-3">
                    <div class="col-12 col-md-5">
                        <label class="form-label" for="prod-bom">Bill of materials *</label>
                        <select id="prod-bom" name="bom_id" class="form-select" required>
                            @foreach ($boms as $bom)
                                <option value="{{ $bom->id }}" @selected((string) old('bom_id', $selected) === (string) $bom->id)>{{ $bom->name }} → {{ $bom->stockItem->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-6 col-md-2">
                        <label class="form-label" for="prod-date">Date *</label>
                        <input id="prod-date" type="date" name="date" value="{{ old('date', now()->toDateString()) }}" class="form-control" required>
                    </div>
                    <div class="col-6 col-md-2">
                        <label class="form-label" for="prod-qty">Quantity produced *</label>
                        <input id="prod-qty" type="number" step="0.001" min="0.001" name="quantity" value="{{ old('quantity') }}" class="form-control" required>
                    </div>
                    <div class="col-12 col-md-3">
                        <label class="form-label" for="prod-other">Labour / other cost</label>
                        <input id="prod-other" type="number" step="0.01" min="0" name="other_cost" value="{{ old('other_cost') }}" class="form-control">
                    </div>
                    <div class="col-12">
                        <div class="small fw-semibold mb-1">Materials that will be used</div>
                        <ul class="small mb-0" id="prod-preview" aria-live="polite"></ul>
                    </div>
                    <div class="col-12">
                        <label class="form-label" for="prod-notes">Notes</label>
                        <textarea id="prod-notes" name="notes" rows="2" maxlength="2000" class="form-control">{{ old('notes') }}</textarea>
                    </div>
                </div>
            </div>
            <div class="card-footer bg-white d-flex justify-content-between align-items-center">
                <small class="text-secondary">Raw materials leave stock at average cost; the finished goods come in at that cost plus other cost.</small>
                <button class="btn btn-primary">Save Production</button>
            </div>
        </div>
    </form>
</div>
<script>
(() => {
    const recipes = @json($recipes);
    const bom = document.getElementById('prod-bom'), qty = document.getElementById('prod-qty'), list = document.getElementById('prod-preview');
    const fmt = n => String(Math.round(n * 1000) / 1000);
    function preview() {
        const r = recipes[bom.value], made = parseFloat(qty.value) || 0;
        list.innerHTML = '';
        if (!r) return;
        r.components.forEach(c => {
            const li = document.createElement('li');
            li.textContent = fmt(c.qty * made / r.out) + ' ' + c.unit + ' ' + c.name;
            list.appendChild(li);
        });
    }
    bom.addEventListener('change', preview);
    qty.addEventListener('input', preview);
    preview();
})();
</script>
@endsection
