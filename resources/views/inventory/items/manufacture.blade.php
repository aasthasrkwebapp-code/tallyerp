@extends('layouts.erp')

@section('title', 'Manufacture '.$item->name)

@php($m = fn ($p) => \App\Support\Money::format($p, paiseInput: true))
@php($total = array_sum(array_map(fn ($n) => $n->cost, $needs)))
@php($recovered = array_sum(array_map(fn ($o) => $o->line->isCoProduct() ? 0 : $o->value, $outputs)))
@php($shared = array_sum(array_map(fn ($o) => $o->line->isCoProduct() ? $o->value : 0, $outputs)))

@section('content')
<div class="page-header">
    <div>
        <h1>Manufacture {{ $item->name }}</h1>
        <p class="text-secondary mb-0">Uses up the components of the bill of materials and adds the finished goods, valued at the components' cost less any by-products and scrap, and less the share taken by any co-products.</p>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <a href="{{ route('stock-items.bom.edit', $item) }}" class="btn btn-outline-secondary">Bill of materials</a>
        <a href="{{ route('stock-items.show', $item) }}" class="btn btn-outline-secondary">Back to item</a>
    </div>
</div>

<form method="GET" class="erp-card p-3 mb-3 no-print" style="max-width: 820px">
    <div class="row g-2 align-items-end">
        <div class="col-6 col-sm-4">
            <label for="q-quantity" class="form-label">Quantity to make</label>
            <div class="input-group">
                <input id="q-quantity" name="quantity" value="{{ $quantity }}" class="form-control text-end" inputmode="decimal">
                <span class="input-group-text">{{ $item->unit->symbol }}</span>
            </div>
        </div>
        <div class="col-6 col-sm-4">
            <label for="q-date" class="form-label">Date</label>
            <input type="date" id="q-date" name="date" value="{{ $date }}" class="form-control">
        </div>
        <div class="col-12 col-sm-auto"><button class="btn btn-outline-primary w-100">Work out components</button></div>
    </div>
</form>

<div class="erp-card mb-3" style="max-width: 820px">
    <div class="erp-card-header">Components needed</div>
    <div class="table-responsive">
        <table class="table table-sm align-middle mb-0 table-stack">
            <thead class="table-light"><tr><th>Component</th><th class="text-end">Needed</th><th class="text-end">In stock</th><th class="text-end">Cost</th></tr></thead>
            <tbody>
            @forelse ($needs as $n)
                <tr>
                    <td data-label="Component"><a href="{{ route('stock-items.show', $n->component) }}">{{ $n->component->name }}</a> <span class="small text-secondary">{{ \App\Models\StockItem::COSTING[$n->component->costing_method] }}</span></td>
                    <td data-label="Needed" class="text-end">{{ \App\Services\Inventory\StockLedger::qty($n->quantity, $n->component->unit) }}</td>
                    <td data-label="In stock" class="text-end {{ $n->in_stock < $n->quantity ? 'text-danger fw-semibold' : '' }}">{{ \App\Services\Inventory\StockLedger::qty($n->in_stock, $n->component->unit) }}@if ($n->in_stock < $n->quantity) <i class="bi bi-exclamation-triangle" title="Not enough in stock"></i>@endif</td>
                    <td data-label="Cost" class="amount">{{ $m($n->cost) }}</td>
                </tr>
            @empty
                <tr><td colspan="4" class="text-center text-secondary py-3">Enter a quantity to see the components.</td></tr>
            @endforelse
            </tbody>
            @if ($needs)
                <tfoot>
                    <tr class="row-total"><td colspan="3">Cost of components</td><td class="amount">{{ $m($total) }}</td></tr>
                    @if ($outputs)
                        @if ($recovered)<tr><td colspan="3">Less by-products and scrap</td><td class="amount">{{ $m(-$recovered) }}</td></tr>@endif
                        @if ($shared)<tr><td colspan="3">Less co-products' share</td><td class="amount">{{ $m(-$shared) }}</td></tr>@endif
                        <tr class="row-total"><td colspan="3">Cost of {{ $item->name }} before other costs</td><td class="amount">{{ $m($mainCost) }}</td></tr>
                    @endif
                </tfoot>
            @endif
        </table>
    </div>
</div>

@can('inventory.create')
<form method="POST" action="{{ route('stock-items.manufacture.store', $item) }}" class="erp-card p-3" style="max-width: 820px" data-confirm="Post this manufacture as a stock journal?">
    @csrf
    <div class="row g-3">
        <div class="col-6 col-md-3">
            <label for="quantity" class="form-label">Quantity</label>
            <input id="quantity" name="quantity" value="{{ old('quantity', $quantity) }}" class="form-control text-end @error('quantity') is-invalid @enderror" inputmode="decimal" required>
            @error('quantity')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <div class="col-6 col-md-3">
            <label for="date" class="form-label">Date</label>
            <input type="date" id="date" name="date" value="{{ old('date', $date) }}" class="form-control @error('date') is-invalid @enderror" required>
            @error('date')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <div class="col-12 col-md-6">
            <label for="other_cost" class="form-label">Other costs added to the value <small class="text-secondary">(labour, power)</small></label>
            <input id="other_cost" name="other_cost" value="{{ old('other_cost') }}" class="form-control text-end @error('other_cost') is-invalid @enderror" inputmode="decimal" placeholder="0.00">
            @error('other_cost')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <div class="col-12 col-md-6">
            <label for="godown_id" class="form-label">Finished goods into</label>
            <select id="godown_id" name="godown_id" class="form-select @error('godown_id') is-invalid @enderror">
                @foreach ($godowns as $g)<option value="{{ $g->id }}" @selected((int) old('godown_id', $defaultGodown) === $g->id)>{{ $g->name }}</option>@endforeach
            </select>
            @error('godown_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <div class="col-12 col-md-6">
            <label for="component_godown_id" class="form-label">Components from</label>
            <select id="component_godown_id" name="component_godown_id" class="form-select @error('component_godown_id') is-invalid @enderror">
                @foreach ($godowns as $g)<option value="{{ $g->id }}" @selected((int) old('component_godown_id', $defaultGodown) === $g->id)>{{ $g->name }}</option>@endforeach
            </select>
            @error('component_godown_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        @if ($outputs)
            <div class="col-12">
                <div class="fw-semibold small mb-1">{{ collect($outputs)->contains(fn ($o) => $o->line->isCoProduct()) ? 'Co-products, by-products and scrap' : 'By-products and scrap' }} obtained</div>
                @error('outputs')<div class="text-danger small mb-1">{{ $message }}</div>@enderror
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0 table-stack">
                        <thead class="table-light"><tr><th>Item</th><th class="text-end">As per BOM</th><th class="text-end">Obtained</th><th class="text-end">Rate</th><th class="text-end">Value</th></tr></thead>
                        <tbody>
                        @foreach ($outputs as $o)
                            <tr>
                                <td data-label="Item"><div>{{ $o->item->name }} <span class="badge text-bg-light">{{ \App\Models\BomComponent::KINDS[$o->line->kind] }}</span>@if ($o->line->isCoProduct()) <span class="small text-secondary">{{ \App\Support\Decimal::trim($o->line->cost_share) }}% of cost</span>@endif</div></td>
                                <td data-label="As per BOM" class="text-end">{{ \App\Services\Inventory\StockLedger::qty($o->quantity, $o->item->unit) }}</td>
                                <td data-label="Obtained" class="text-end">
                                    <input name="outputs[{{ $o->line->id }}]" value="{{ old('outputs.'.$o->line->id) }}" placeholder="{{ \App\Support\Decimal::trim(\App\Support\Decimal::fromScaled($o->quantity, 3)) }}" style="max-width: 9rem; margin-left: auto" class="form-control form-control-sm text-end @error('outputs.'.$o->line->id) is-invalid @enderror" inputmode="decimal" aria-label="{{ $o->item->name }} obtained">
                                    @error('outputs.'.$o->line->id)<div class="invalid-feedback">{{ $message }}</div>@enderror
                                </td>
                                <td data-label="Rate" class="amount">{{ $m($o->rate) }}</td>
                                <td data-label="Value" class="amount">{{ $m($o->value) }}</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="small text-secondary mt-1">Leave blank to take the quantity as per the bill of materials, or enter what actually came out (0 for none). A co-product's value is its share of the cost including other costs, so it is final only when posted; a co-product not obtained leaves its share to {{ $item->name }}.</div>
            </div>
        @endif
        <div class="col-12">
            <label for="narration" class="form-label">Narration</label>
            <input id="narration" name="narration" value="{{ old('narration') }}" class="form-control" maxlength="1000">
        </div>
    </div>
    <button class="btn btn-primary mt-3">Manufacture</button>
    <div class="small text-secondary mt-2">Other costs only add to the stock value; book the expense itself with a voucher as usual.</div>
</form>
@endcan
@endsection
