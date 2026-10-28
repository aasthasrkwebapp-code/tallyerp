@extends('layouts.erp')

@section('title', 'Bill of Materials: '.$item->name)

@php($lines = old('components', $item->bomComponents->map(fn ($c) => ['component_id' => $c->component_id, 'kind' => $c->kind, 'quantity' => \App\Support\Decimal::trim($c->quantity), 'rate' => $c->rate, 'cost_share' => $c->cost_share !== null ? \App\Support\Decimal::trim($c->cost_share) : null])->all()))
@php($lines = array_values($lines ?: []))
@php($lines = array_merge($lines, array_fill(0, max(1, 3 - count($lines)), ['component_id' => null, 'kind' => 'component', 'quantity' => null, 'rate' => null, 'cost_share' => null])))

@section('content')
<div class="page-header">
    <div>
        <h1>Bill of materials</h1>
        <p class="text-secondary mb-0">The components that make {{ $item->name }}, and any co-products, by-products or scrap that come out with it.</p>
    </div>
    <a href="{{ route('stock-items.show', $item) }}" class="btn btn-outline-secondary">Back to item</a>
</div>

<form method="POST" action="{{ route('stock-items.bom.update', $item) }}" style="max-width: 820px">
    @csrf @method('PUT')
    <div class="erp-card p-3 mb-3">
        <label for="bom_quantity" class="form-label">These components make</label>
        <div class="input-group" style="max-width: 260px">
            <input id="bom_quantity" name="bom_quantity" value="{{ old('bom_quantity', $item->bom_quantity !== null ? \App\Support\Decimal::trim($item->bom_quantity) : '1') }}" class="form-control text-end @error('bom_quantity') is-invalid @enderror" inputmode="decimal" required>
            <span class="input-group-text">{{ $item->unit->symbol }}</span>
        </div>
        @error('bom_quantity')<div class="text-danger small mt-1">{{ $message }}</div>@enderror
    </div>

    <div class="erp-card mb-3">
        <div class="erp-card-header d-flex justify-content-between align-items-center">
            <span>Components and what comes out</span>
            <button type="button" class="btn btn-sm btn-outline-primary" id="add-component"><i class="bi bi-plus-lg"></i> Add line</button>
        </div>
        @error('components')<div class="text-danger small px-3 pt-2">{{ $message }}</div>@enderror
        <div id="components" class="p-3 pb-0">
            @foreach ($lines as $i => $line)
                <div class="row g-2 mb-2 component-line">
                    <div class="col-12 col-md-5">
                        <select name="components[{{ $i }}][component_id]" class="form-select @error("components.$i.component_id") is-invalid @enderror" aria-label="Item {{ $i + 1 }}">
                            <option value="">Choose an item</option>
                            @foreach ($items as $c)<option value="{{ $c->id }}" @selected((int) ($line['component_id'] ?? 0) === $c->id)>{{ $c->name }} ({{ $c->unit->symbol }})</option>@endforeach
                        </select>
                        @error("components.$i.component_id")<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-4 col-md-3">
                        <select name="components[{{ $i }}][kind]" class="form-select line-kind" aria-label="Type {{ $i + 1 }}">
                            @foreach (\App\Models\BomComponent::KINDS as $k => $label)<option value="{{ $k }}" @selected(($line['kind'] ?? 'component') === $k)>{{ $label }}</option>@endforeach
                        </select>
                    </div>
                    <div class="col-4 col-md-2">
                        <input name="components[{{ $i }}][quantity]" value="{{ $line['quantity'] ?? '' }}" class="form-control text-end @error("components.$i.quantity") is-invalid @enderror" inputmode="decimal" placeholder="Quantity" aria-label="Quantity {{ $i + 1 }}">
                        @error("components.$i.quantity")<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    @php($co = ($line['kind'] ?? 'component') === 'co_product')
                    <div class="col-4 col-md-2">
                        <input name="components[{{ $i }}][rate]" value="{{ $line['rate'] ?? '' }}" class="form-control text-end line-rate @if ($co) d-none @endif @error("components.$i.rate") is-invalid @enderror" inputmode="decimal" placeholder="Rate" aria-label="Rate {{ $i + 1 }}" @disabled(($line['kind'] ?? 'component') === 'component' || $co)>
                        @error("components.$i.rate")<div class="invalid-feedback">{{ $message }}</div>@enderror
                        <div class="input-group line-share @unless ($co) d-none @endunless">
                            <input name="components[{{ $i }}][cost_share]" value="{{ $line['cost_share'] ?? '' }}" class="form-control text-end @error("components.$i.cost_share") is-invalid @enderror" inputmode="decimal" placeholder="Cost" aria-label="Share of cost {{ $i + 1 }}" @disabled(! $co)>
                            <span class="input-group-text">%</span>
                        </div>
                        @error("components.$i.cost_share")<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                    </div>
                </div>
            @endforeach
        </div>
        <div class="border-top px-3 py-2 small text-secondary">Quantities are in each item's own unit. By-products and scrap come out with the finished item; their rate per unit (blank: the item's cost on the day) is taken off the finished item's cost. Co-products come out too and take their percentage of what is left; {{ $item->name }} takes the rest. To remove a line, clear its item and quantity.</div>
    </div>

    <div class="d-flex flex-wrap gap-2 mb-5">
        <button class="btn btn-primary" data-shortcut="s">Save bill of materials</button>
    </div>
</form>
@if ($item->bomComponents->isNotEmpty())
    <form method="POST" action="{{ route('stock-items.bom.destroy', $item) }}" data-confirm="Remove the bill of materials of {{ $item->name }}?" class="mb-5">
        @csrf @method('DELETE')
        <button class="btn btn-outline-danger">Remove bill of materials</button>
    </form>
@endif

@push('scripts')
<script>
(function () {
    var box = document.getElementById('components');
    document.getElementById('add-component').addEventListener('click', function () {
        var rows = box.querySelectorAll('.component-line');
        var copy = rows[rows.length - 1].cloneNode(true);
        var n = rows.length;
        copy.querySelectorAll('[name]').forEach(function (el) {
            el.name = el.name.replace(/components\[\d+\]/, 'components[' + n + ']');
            if (el.tagName === 'SELECT') el.selectedIndex = 0; else el.value = '';
            if (el.classList.contains('line-rate')) { el.disabled = true; el.classList.remove('d-none'); }
            if (el.name.indexOf('[cost_share]') !== -1) el.disabled = true;
            el.classList.remove('is-invalid');
        });
        copy.querySelectorAll('.invalid-feedback').forEach(function (el) { el.remove(); });
        copy.querySelector('.line-share').classList.add('d-none');
        box.appendChild(copy);
        copy.querySelector('select').focus();
    });
    // The rate applies only to by-products and scrap, the cost share only to co-products.
    box.addEventListener('change', function (e) {
        if (!e.target.classList.contains('line-kind')) return;
        var row = e.target.closest('.component-line');
        var rate = row.querySelector('.line-rate');
        var share = row.querySelector('.line-share');
        var co = e.target.value === 'co_product';
        rate.disabled = e.target.value === 'component' || co;
        rate.classList.toggle('d-none', co);
        if (rate.disabled) rate.value = '';
        share.classList.toggle('d-none', !co);
        share.querySelector('input').disabled = !co;
    });
})();
</script>
@endpush
@endsection
