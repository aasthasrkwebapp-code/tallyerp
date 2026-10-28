@extends('layouts.erp')

@section('title', $config['plural'])

@php
    $r = $editing;
    $v = fn ($name, $default = null) => old($name, $r?->{$name} instanceof \DateTimeInterface ? $r->{$name}->toDateString() : ($r?->{$name} ?? $default));
    $fields = match ($master) {
        'vehicles' => [['number', 'Registration number', 'required maxlength=20 placeholder=MH12AB1234 style=text-transform:uppercase'], ['type', 'Type', 'maxlength=50 placeholder=Truck'], ['make_model', 'Make / model', 'maxlength=100'], ['capacity_tonnes', 'Capacity (tonnes)', 'inputmode=decimal']],
        'drivers' => [['name', 'Name', 'required maxlength=150'], ['phone', 'Phone', 'maxlength=20'], ['licence_number', 'Licence number', 'maxlength=30'], ['licence_expiry', 'Licence valid till', '', 'date']],
        'routes' => [['origin', 'From', 'required maxlength=100'], ['destination', 'To', 'required maxlength=100'], ['distance_km', 'Distance (km)', 'inputmode=decimal'], ['name', 'Route name', 'maxlength=150 placeholder="From – To"']],
    };
@endphp

@section('content')
<div class="page-header">
    <div>
        <h1>{{ $config['plural'] }}</h1>
        <p class="text-secondary mb-0">Transport masters used on trips and expenses.</p>
    </div>
</div>

<ul class="nav nav-pills mb-3 flex-nowrap overflow-auto">
    @foreach (\App\Http\Controllers\Transport\TransportMasterController::MASTERS as $key => $m)
        <li class="nav-item"><a href="{{ route('transport-masters.index', $key) }}" class="nav-link text-nowrap {{ $key === $master ? 'active' : '' }}">{{ $m['plural'] }}</a></li>
    @endforeach
</ul>

<div class="row g-3">
    <div class="col-12 col-xl-8">
        <div class="erp-card">
            <div class="table-responsive">
                <table class="table align-middle mb-0 table-stack">
                    <thead class="table-light">
                        <tr>
                            @if ($master === 'vehicles')<th>Number</th><th>Type</th><th>Ownership</th><th>Status</th>
                            @elseif ($master === 'drivers')<th>Driver</th><th>Phone</th><th>Licence</th><th>Advance ledger</th>
                            @else<th>Route</th><th>From</th><th>To</th><th class="text-end">Km</th>@endif
                            <th class="text-end">Trips</th><th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    @forelse ($records as $rec)
                        <tr>
                            @if ($master === 'vehicles')
                                <td data-label="Number" class="fw-semibold">{{ $rec->number }}</td>
                                <td data-label="Type">{{ $rec->type ?: '—' }}@if ($rec->make_model) <span class="text-secondary small">· {{ $rec->make_model }}</span>@endif</td>
                                <td data-label="Ownership">{{ $rec->ownership === 'own' ? 'Own' : 'Hired' }}</td>
                                <td data-label="Status">{{ $rec->is_active ? 'Active' : 'Inactive' }}</td>
                            @elseif ($master === 'drivers')
                                <td data-label="Driver" class="fw-semibold">{{ $rec->name }} @unless ($rec->is_active)<span class="badge text-bg-secondary">Inactive</span>@endunless</td>
                                <td data-label="Phone">{{ $rec->phone ?: '—' }}</td>
                                <td data-label="Licence">{{ $rec->licence_number ?: '—' }}@if ($rec->licence_expiry) <span class="small {{ $rec->licence_expiry->isPast() ? 'text-danger' : 'text-secondary' }}">till {{ $rec->licence_expiry->format('d-m-Y') }}</span>@endif</td>
                                <td data-label="Advance ledger">@can('reports.view')<a href="{{ route('reports.ledger', ['ledger' => $rec->account_id]) }}">{{ $rec->account->name }}</a>@else{{ $rec->account->name }}@endcan</td>
                            @else
                                <td data-label="Route" class="fw-semibold">{{ $rec->name }}</td>
                                <td data-label="From">{{ $rec->origin }}</td>
                                <td data-label="To">{{ $rec->destination }}</td>
                                <td data-label="Km" class="text-end">{{ $rec->distance_km !== null ? \App\Support\Decimal::trim($rec->distance_km) : '—' }}</td>
                            @endif
                            <td data-label="Trips" class="text-end">{{ $tripCounts[$rec->id] ?? 0 }}</td>
                            <td data-label="" class="text-end text-nowrap">
                                @if ($master === 'vehicles')<a href="{{ route('vehicle-documents.index', ['vehicle' => $rec->id, 'status' => 'all']) }}" class="btn btn-sm btn-outline-secondary">Documents</a>@endif
                                @can('transport.edit')<a href="{{ route('transport-masters.index', [$master, 'edit' => $rec->id]) }}" class="btn btn-sm btn-outline-secondary">Edit</a>@endcan
                                @can('transport.delete')
                                    <form method="POST" action="{{ route('transport-masters.destroy', [$master, $rec->id]) }}" class="d-inline" data-confirm="Delete {{ strtolower($config['label']) }} {{ $rec->number ?? $rec->name }}?">
                                        @csrf @method('DELETE')
                                        <button class="btn btn-sm btn-outline-danger" aria-label="Delete"><i class="bi bi-trash"></i></button>
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
        @if ($r ? auth()->user()->can('transport.edit') : auth()->user()->can('transport.create'))
            <form method="POST" action="{{ $r ? route('transport-masters.update', [$master, $r->id]) : route('transport-masters.store', $master) }}" class="erp-card p-3">
                @csrf
                @if ($r) @method('PUT') @endif
                <h2 class="h6 fw-bold">{{ $r ? 'Edit '.strtolower($config['label']) : 'New '.strtolower($config['label']) }}</h2>
                @foreach ($fields as $f)
                    <div class="mb-2">
                        <label for="{{ $f[0] }}" class="form-label">{{ $f[1] }}</label>
                        <input id="{{ $f[0] }}" name="{{ $f[0] }}" type="{{ $f[3] ?? 'text' }}" value="{{ $v($f[0]) }}" class="form-control @error($f[0]) is-invalid @enderror" {!! $f[2] !!}>
                        @error($f[0])<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                @endforeach
                @if ($master === 'vehicles')
                    <div class="mb-2">
                        <label for="ownership" class="form-label">Ownership</label>
                        <select id="ownership" name="ownership" class="form-select">
                            <option value="own" @selected($v('ownership', 'own') === 'own')>Own vehicle</option>
                            <option value="hired" @selected($v('ownership') === 'hired')>Hired / attached</option>
                        </select>
                    </div>
                @endif
                @if ($master !== 'routes')
                    <div class="form-check mb-2">
                        <input type="hidden" name="is_active" value="0">
                        <input class="form-check-input" type="checkbox" name="is_active" value="1" id="is_active" @checked($v('is_active', true))>
                        <label class="form-check-label" for="is_active">Active</label>
                    </div>
                @endif
                @if ($master === 'drivers' && ! $r)<p class="small text-secondary">A driver advance ledger is created under Loans &amp; Advances (Asset) for money given to the driver.</p>@endif
                <div class="d-flex gap-2 mt-3">
                    <button class="btn btn-primary flex-grow-1">{{ $r ? 'Save changes' : 'Add '.strtolower($config['label']) }}</button>
                    @if ($r)<a href="{{ route('transport-masters.index', $master) }}" class="btn btn-outline-secondary">Cancel</a>@endif
                </div>
            </form>
        @endif
    </div>
</div>
@endsection
