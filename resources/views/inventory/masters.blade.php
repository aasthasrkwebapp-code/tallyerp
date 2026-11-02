@extends('layouts.erp')

@section('title', $config['plural'])

@php($r = $editing)
@php($field = fn ($name, $default = null) => old($name, $r?->{$name} ?? $default))

@section('content')
<div class="page-header">
    <div>
        <h1>{{ $config['plural'] }}</h1>
        <p class="text-secondary mb-0">Inventory masters used by stock items.</p>
    </div>
</div>

<ul class="nav nav-pills mb-3 flex-nowrap overflow-auto">
    @foreach (\App\Http\Controllers\Inventory\InventoryMasterController::MASTERS as $key => $m)
        <li class="nav-item"><a href="{{ route('inventory-masters.index', $key) }}" class="nav-link text-nowrap {{ $key === $master ? 'active' : '' }}">{{ $m['plural'] }}</a></li>
    @endforeach
    <li class="nav-item"><a href="{{ route('stock-items.index') }}" class="nav-link text-nowrap">Stock items</a></li>
</ul>

<div class="row g-3">
    <div class="col-12 col-xl-8">
        <div class="erp-card">
            <div class="table-responsive">
                <table class="table align-middle mb-0 table-stack">
                    <thead class="table-light">
                        <tr>
                            @if ($master === 'units')<th>Symbol</th><th>Name</th><th>Decimals</th><th>UQC</th>
                            @elseif ($master === 'godowns')<th>Godown</th><th>Address</th><th>Status</th>
                            @else<th>Name</th>@endif
                            <th class="text-end">Items</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    @forelse ($records as $record)
                        <tr>
                            @if ($master === 'units')
                                <td data-label="Symbol" class="fw-semibold">{{ $record->symbol }}</td>
                                <td data-label="Name">{{ $record->name }}</td>
                                <td data-label="Decimals">{{ $record->decimal_places }}</td>
                                <td data-label="UQC">{{ $record->uqc ?: '—' }}</td>
                            @elseif ($master === 'godowns')
                                <td data-label="Godown" class="fw-semibold">{{ $record->name }} @if ($record->is_default)<span class="badge text-bg-primary">Default</span>@endif</td>
                                <td data-label="Address">{{ $record->address ?: '—' }}</td>
                                <td data-label="Status">{{ $record->is_active ? 'Active' : 'Inactive' }}</td>
                            @else
                                <td data-label="Name"><span class="indent-{{ min($record->depth ?? 0, 3) }}">{{ $record->name }}</span></td>
                            @endif
                            <td data-label="Items" class="text-end">
                                @if ($master !== 'units' && ($counts[$record->id] ?? 0))
                                    <a href="{{ route('stock-items.index', [['stock-groups' => 'group', 'stock-categories' => 'category', 'godowns' => 'godown'][$master] => $record->id]) }}">{{ $counts[$record->id] }}</a>
                                @else
                                    {{ $counts[$record->id] ?? 0 }}
                                @endif
                            </td>
                            <td data-label="" class="text-end text-nowrap">
                                @can('inventory.edit')
                                    <a href="{{ route('inventory-masters.index', [$master, 'edit' => $record->id]) }}" class="btn btn-sm btn-outline-secondary">Edit</a>
                                @endcan
                                @can('inventory.delete')
                                    <form method="POST" action="{{ route('inventory-masters.destroy', [$master, $record->id]) }}" class="d-inline" data-confirm="Delete {{ strtolower($config['label']) }} {{ $record->symbol ?? $record->name }}?">
                                        @csrf @method('DELETE')
                                        <button class="btn btn-sm btn-outline-danger" aria-label="Delete {{ $record->symbol ?? $record->name }}"><i class="bi bi-trash"></i></button>
                                    </form>
                                @endcan
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="text-secondary text-center py-4">No {{ strtolower($config['plural']) }} yet.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="col-12 col-xl-4">
        @if ($r ? auth()->user()->can('inventory.edit') : auth()->user()->can('inventory.create'))
            <form method="POST" action="{{ $r ? route('inventory-masters.update', [$master, $r->id]) : route('inventory-masters.store', $master) }}" class="erp-card p-3">
                @csrf
                @if ($r) @method('PUT') @endif
                <h2 class="h6 fw-bold">{{ $r ? 'Edit '.strtolower($config['label']) : 'New '.strtolower($config['label']) }}</h2>

                @if ($master === 'units')
                    <div class="mb-2">
                        <label for="symbol" class="form-label">Symbol</label>
                        <input id="symbol" name="symbol" value="{{ $field('symbol') }}" class="form-control @error('symbol') is-invalid @enderror" required maxlength="20" placeholder="Nos">
                        @error('symbol')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="mb-2">
                        <label for="name" class="form-label">Formal name</label>
                        <input id="name" name="name" value="{{ $field('name') }}" class="form-control @error('name') is-invalid @enderror" required maxlength="100" placeholder="Numbers">
                        @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label for="decimal_places" class="form-label">Decimal places</label>
                            <select id="decimal_places" name="decimal_places" class="form-select">
                                @foreach ([0, 1, 2, 3] as $d)<option value="{{ $d }}" @selected((int) $field('decimal_places', 0) === $d)>{{ $d }}</option>@endforeach
                            </select>
                        </div>
                        <div class="col-6">
                            <label for="uqc" class="form-label">GST UQC</label>
                            <input id="uqc" name="uqc" value="{{ $field('uqc') }}" class="form-control text-uppercase @error('uqc') is-invalid @enderror" maxlength="3" placeholder="NOS">
                            @error('uqc')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                    </div>
                @else
                    <div class="mb-2">
                        <label for="name" class="form-label">Name</label>
                        <input id="name" name="name" value="{{ $field('name') }}" class="form-control @error('name') is-invalid @enderror" required maxlength="150">
                        @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    @if ($master === 'stock-groups')
                        <div class="mb-3">
                            <label for="parent_id" class="form-label">Under</label>
                            <select id="parent_id" name="parent_id" class="form-select @error('parent_id') is-invalid @enderror">
                                <option value="">Primary</option>
                                @foreach ($groups as $g)
                                    @continue($r && $g->id === $r->id)
                                    <option value="{{ $g->id }}" @selected((int) $field('parent_id') === $g->id)>{{ str_repeat('— ', $g->depth) }}{{ $g->name }}</option>
                                @endforeach
                            </select>
                            @error('parent_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                    @elseif ($master === 'godowns')
                        <div class="mb-2">
                            <label for="address" class="form-label">Address</label>
                            <textarea id="address" name="address" rows="2" class="form-control" maxlength="500">{{ $field('address') }}</textarea>
                        </div>
                        <div class="form-check mb-1">
                            <input type="hidden" name="is_active" value="0">
                            <input class="form-check-input" type="checkbox" name="is_active" value="1" id="is_active" @checked($field('is_active', true))>
                            <label class="form-check-label" for="is_active">Active</label>
                        </div>
                        <div class="form-check mb-3">
                            <input class="form-check-input" type="checkbox" name="is_default" value="1" id="is_default" @checked($field('is_default', false))>
                            <label class="form-check-label" for="is_default">Default godown (used when none is chosen)</label>
                        </div>
                    @else
                        <div class="mb-3"></div>
                    @endif
                @endif

                <div class="d-flex gap-2">
                    <button class="btn btn-primary flex-grow-1">{{ $r ? 'Save changes' : 'Add '.strtolower($config['label']) }}</button>
                    @if ($r)<a href="{{ route('inventory-masters.index', $master) }}" class="btn btn-outline-secondary">Cancel</a>@endif
                </div>
            </form>
        @endif
    </div>
</div>
@endsection
