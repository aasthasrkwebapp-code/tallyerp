@extends('layouts.erp')

@section('title', 'Godowns')

@section('content')
@php
    $q = fn ($v) => rtrim(rtrim(inr((float) $v, 3), '0'), '.');
    $locations = collect([0 => \App\Models\Godown::MAIN])->union($godowns->pluck('name', 'id'));
@endphp
<div class="container-fluid py-3">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
        <div>
            <h2 class="h4 mb-1">Godowns &amp; Stock Transfers</h2>
            <p class="text-secondary mb-0">{{ $company->name }} · stock by location as on {{ $on->format('d-m-Y') }}</p>
        </div>
        <a href="{{ route('companies.stock-items.index', $company) }}" class="btn btn-outline-secondary">Back to Inventory</a>
    </div>

    @if (session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
    @if (session('error'))<div class="alert alert-danger">{{ session('error') }}</div>@endif
    @if ($errors->any())<div class="alert alert-danger"><ul class="mb-0">@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>@endif

    <div class="row g-4 mb-4">
        <div class="col-lg-4">
            <h3 class="h6">Godowns</h3>
            <div class="card border-0 shadow-sm">
                <ul class="list-group list-group-flush">
                    <li class="list-group-item">{{ \App\Models\Godown::MAIN }} <span class="small text-secondary">(opening stock and anything without a godown)</span></li>
                    @foreach ($godowns as $g)
                        <li class="list-group-item d-flex justify-content-between align-items-center">
                            {{ $g->name }}
                            <form method="POST" action="{{ route('companies.godowns.destroy', [$company, $g]) }}" onsubmit="return confirm('Delete {{ $g->name }}?')">@csrf @method('DELETE')<button class="btn btn-sm btn-outline-danger">&times;</button></form>
                        </li>
                    @endforeach
                </ul>
                <form method="POST" action="{{ route('companies.godowns.store', $company) }}" class="card-body d-flex gap-2">
                    @csrf
                    <input name="name" class="form-control form-control-sm" placeholder="New godown, e.g. Warehouse 2" required>
                    <button class="btn btn-sm btn-primary text-nowrap">Add</button>
                </form>
            </div>
        </div>

        <div class="col-lg-8">
            <h3 class="h6">Stock journal: move stock</h3>
            <form method="POST" action="{{ route('companies.godowns.transfer', $company) }}" class="card border-0 shadow-sm card-body">
                @csrf
                <div class="row g-2 align-items-end">
                    <div class="col-md-2"><label class="form-label small">Date</label><input type="date" name="date" value="{{ old('date', $on->toDateString()) }}" class="form-control form-control-sm" required></div>
                    <div class="col-md-3"><label class="form-label small">Item</label>
                        <select name="stock_item_id" class="form-select form-select-sm" required>
                            @foreach ($items as $item)<option value="{{ $item->id }}" @selected(old('stock_item_id') == $item->id)>{{ $item->name }}</option>@endforeach
                        </select>
                    </div>
                    <div class="col-md-2"><label class="form-label small">From</label>
                        <select name="from_godown_id" class="form-select form-select-sm">@foreach ($locations as $id => $name)<option value="{{ $id ?: '' }}" @selected((string) old('from_godown_id') === (string) ($id ?: ''))>{{ $name }}</option>@endforeach</select>
                    </div>
                    <div class="col-md-2"><label class="form-label small">To</label>
                        <select name="to_godown_id" class="form-select form-select-sm">@foreach ($locations as $id => $name)<option value="{{ $id ?: '' }}" @selected((string) old('to_godown_id', $godowns->first()?->id) === (string) ($id ?: ''))>{{ $name }}</option>@endforeach</select>
                    </div>
                    <div class="col-md-1"><label class="form-label small">Qty</label><input type="number" step="0.001" min="0.001" name="quantity" value="{{ old('quantity') }}" class="form-control form-control-sm" required></div>
                    <div class="col-md-2"><button class="btn btn-sm btn-primary w-100" @disabled($godowns->isEmpty())>Move</button></div>
                    <div class="col-12"><input name="notes" value="{{ old('notes') }}" class="form-control form-control-sm" placeholder="Notes (optional)"></div>
                </div>
                @if ($godowns->isEmpty())<div class="form-text">Add a godown first.</div>@endif
            </form>
        </div>
    </div>

    <h3 class="h6">Stock by location</h3>
    <div class="card border-0 shadow-sm mb-4">
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead class="table-light"><tr><th>Item</th>@foreach ($locations as $name)<th class="text-end">{{ $name }}</th>@endforeach<th class="text-end">Total</th></tr></thead>
                <tbody>
                    @foreach ($items as $item)
                        @php $row = $stock[$item->id] ?? []; @endphp
                        <tr>
                            <td>{{ $item->name }}</td>
                            @foreach ($locations as $id => $name)
                                <td class="text-end {{ ($row[$id] ?? 0) < 0 ? 'text-danger' : '' }}">{{ ($row[$id] ?? 0) != 0 ? $q($row[$id]) : '' }}</td>
                            @endforeach
                            <td class="text-end fw-semibold">{{ $q(array_sum($row)) }} {{ $item->unit->symbol }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    <h3 class="h6">Recent stock journals</h3>
    <div class="card border-0 shadow-sm">
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead class="table-light"><tr><th>#</th><th>Date</th><th>Item</th><th>From → To</th><th class="text-end">Qty</th><th>Notes</th><th></th></tr></thead>
                <tbody>
                    @forelse ($transfers as $t)
                        @php $out = $t->movements->firstWhere('quantity_out', '>', 0); $in = $t->movements->firstWhere('quantity_in', '>', 0); @endphp
                        <tr>
                            <td>{{ $t->number }}</td>
                            <td>{{ $t->date->format('d-m-Y') }}</td>
                            <td>{{ $in?->stockItem->name }}</td>
                            <td>{{ $out?->godown?->name ?? \App\Models\Godown::MAIN }} → {{ $in?->godown?->name ?? \App\Models\Godown::MAIN }}</td>
                            <td class="text-end">{{ $in ? $q($in->quantity_in).' '.$in->stockItem->unit->symbol : '' }}</td>
                            <td class="small">{{ $t->notes }}</td>
                            <td class="text-end"><form method="POST" action="{{ route('companies.godowns.transfer.destroy', [$company, $t]) }}" onsubmit="return confirm('Delete this stock journal?')">@csrf @method('DELETE')<button class="btn btn-sm btn-outline-danger">&times;</button></form></td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="text-center text-secondary py-3">No stock journals yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
