@extends('layouts.erp')

@section('title', $bom->exists ? 'Edit Bill of Materials' : 'New Bill of Materials')

@section('content')
@php
    $rows = old('components', $bom->exists
        ? $bom->components->map(fn ($c) => ['stock_item_id' => $c->stock_item_id, 'quantity' => (float) $c->quantity])->all()
        : [['stock_item_id' => '', 'quantity' => '']]);
@endphp
<div class="container-fluid py-3">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
        <div>
            <h2 class="h4 mb-1">{{ $bom->exists ? 'Edit Bill of Materials' : 'New Bill of Materials' }}</h2>
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

    <form method="POST" action="{{ $bom->exists ? route('companies.manufacturing.bom.update', [$company, $bom]) : route('companies.manufacturing.bom.store', $company) }}">
        @csrf
        @if ($bom->exists)
            @method('PUT')
        @endif
        <div class="card border-0 shadow-sm">
            <div class="card-body p-3 p-md-4">
                <div class="row g-3 mb-4">
                    <div class="col-12 col-md-4">
                        <label class="form-label" for="bom-name">Name *</label>
                        <input id="bom-name" name="name" value="{{ old('name', $bom->name) }}" class="form-control" required maxlength="255" placeholder="e.g. Gift Box recipe">
                    </div>
                    <div class="col-8 col-md-5">
                        <label class="form-label" for="bom-item">Finished item *</label>
                        <select id="bom-item" name="stock_item_id" class="form-select" required>
                            <option value="">Select item</option>
                            @foreach ($items as $item)
                                <option value="{{ $item->id }}" @selected((string) old('stock_item_id', $bom->stock_item_id) === (string) $item->id)>{{ $item->name }} ({{ $item->unit->symbol }})</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-4 col-md-3">
                        <label class="form-label" for="bom-qty">Quantity made *</label>
                        <input id="bom-qty" type="number" step="0.001" min="0.001" name="output_quantity" value="{{ old('output_quantity', (float) $bom->output_quantity) }}" class="form-control" required>
                    </div>
                </div>

                <h3 class="h6">Components used for that quantity</h3>
                <table class="table align-middle" id="components">
                    <thead class="table-light"><tr><th>Raw material / component</th><th style="width: 180px">Quantity</th><th style="width: 40px"></th></tr></thead>
                    <tbody>
                        @foreach ($rows as $i => $row)
                            <tr>
                                <td>
                                    <select name="components[{{ $i }}][stock_item_id]" class="form-select" required aria-label="Component">
                                        <option value="">Select item</option>
                                        @foreach ($items as $item)
                                            <option value="{{ $item->id }}" @selected((string) ($row['stock_item_id'] ?? '') === (string) $item->id)>{{ $item->name }} ({{ $item->unit->symbol }})</option>
                                        @endforeach
                                    </select>
                                </td>
                                <td><input type="number" step="0.001" min="0.001" name="components[{{ $i }}][quantity]" value="{{ $row['quantity'] ?? '' }}" class="form-control" required aria-label="Quantity"></td>
                                <td><button type="button" class="btn btn-sm btn-outline-danger js-remove" title="Remove">&times;</button></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                <button type="button" class="btn btn-sm btn-outline-primary" id="add-component">+ Add component</button>
            </div>
            <div class="card-footer bg-white text-end"><button class="btn btn-primary">Save</button></div>
        </div>
    </form>
</div>
<script>
(() => {
    const body = document.querySelector('#components tbody');
    let next = body.rows.length;
    document.getElementById('add-component').addEventListener('click', () => {
        const row = body.rows[0].cloneNode(true);
        row.querySelectorAll('select, input').forEach(el => { el.name = el.name.replace(/components\[\d+\]/, 'components[' + next + ']'); el.value = ''; });
        next++;
        body.appendChild(row);
    });
    body.addEventListener('click', e => {
        if (e.target.classList.contains('js-remove') && body.rows.length > 1) e.target.closest('tr').remove();
    });
})();
</script>
@endsection
