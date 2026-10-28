@extends('layouts.erp')

@section('title', 'Tyre '.$tyre->serial_no)

@php($p = fn ($paise) => \App\Support\Money::format($paise, paiseInput: true))
@php($badge = ['stock' => 'text-bg-secondary', 'fitted' => 'text-bg-success', 'scrapped' => 'text-bg-dark'])
@php($current = $figures->current)

@section('content')
<div class="page-header">
    <div>
        <h1>Tyre {{ $tyre->serial_no }} <span class="badge {{ $badge[$tyre->status] }} align-middle">{{ \App\Models\Tyre::STATUSES[$tyre->status] }}</span>@if ($figures->due) <span class="badge text-bg-danger align-middle">Life reached</span>@endif</h1>
        <p class="text-secondary mb-0">{{ trim($tyre->brand.' '.$tyre->size) ?: 'Tyre' }}@if ($current) · on {{ $current->vehicle->number }} at {{ $current->position }} since {{ $current->fitted_on->format('d-m-Y') }}@endif @if ($tyre->status === 'scrapped') · scrapped {{ $tyre->scrapped_on?->format('d-m-Y') }} ({{ $tyre->scrap_reason }})@endif</p>
    </div>
    <a href="{{ route('tyres.index') }}" class="btn btn-outline-secondary">All tyres</a>
</div>

<div class="row g-3 mb-3">
    <div class="col-6 col-lg-3"><div class="erp-card p-3 h-100"><div class="small text-secondary">Km run</div><div class="fs-5 fw-semibold">{{ number_format($figures->km) }}</div>@if ($tyre->expected_km)<div class="small text-secondary">expected {{ number_format($tyre->expected_km) }}</div>@endif</div></div>
    <div class="col-6 col-lg-3"><div class="erp-card p-3 h-100"><div class="small text-secondary">Cost incl. retreads</div><div class="fs-5 fw-semibold">{{ $p($figures->cost) }}</div>@if ($tyre->retreads)<div class="small text-secondary">{{ $tyre->retreads }} {{ \Illuminate\Support\Str::plural('retread', $tyre->retreads) }}, {{ \App\Support\Money::format($tyre->retread_cost) }}</div>@endif</div></div>
    <div class="col-6 col-lg-3"><div class="erp-card p-3 h-100"><div class="small text-secondary">Cost per km</div><div class="fs-5 fw-semibold">{{ $figures->per_km !== null ? $p($figures->per_km) : '—' }}</div></div></div>
    <div class="col-6 col-lg-3"><div class="erp-card p-3 h-100"><div class="small text-secondary">Fitments</div><div class="fs-5 fw-semibold">{{ $tyre->fitments->count() }}</div></div></div>
</div>

<div class="row g-3">
    <div class="col-12 col-xl-4">
        @if ($tyre->status === 'stock')
            @can('transport.edit')
                <form method="POST" action="{{ route('tyres.fit', $tyre) }}" class="erp-card p-3 mb-3">
                    @csrf
                    <h2 class="h6 fw-bold">Fit to a vehicle</h2>
                    @error('tyre')<div class="alert alert-danger small py-2">{{ $message }}</div>@enderror
                    <div class="row g-2">
                        <div class="col-6"><label for="vehicle_id" class="form-label small">Vehicle</label>
                            <select id="vehicle_id" name="vehicle_id" class="form-select form-select-sm @error('vehicle_id') is-invalid @enderror" required>
                                <option value="">Choose…</option>
                                @foreach ($vehicles as $veh)<option value="{{ $veh->id }}" @selected((int) old('vehicle_id') === $veh->id)>{{ $veh->number }}{{ $odometers[$veh->id] !== null ? ' ('.number_format($odometers[$veh->id]).' km)' : '' }}</option>@endforeach
                            </select>
                        </div>
                        <div class="col-6"><label for="position" class="form-label small">Position</label>
                            <input id="position" name="position" value="{{ old('position') }}" list="positions" class="form-control form-control-sm text-uppercase @error('position') is-invalid @enderror" maxlength="12" required>
                            <datalist id="positions">@foreach (\App\Models\Tyre::POSITIONS as $k => $label)<option value="{{ $k }}">{{ $label }}</option>@endforeach</datalist>
                        </div>
                        <div class="col-6"><label for="fit_date" class="form-label small">Date</label><input id="fit_date" type="date" name="date" value="{{ old('date', now()->toDateString()) }}" class="form-control form-control-sm @error('date') is-invalid @enderror" required></div>
                        <div class="col-6"><label for="fit_km" class="form-label small">Odometer (km)</label><input id="fit_km" name="km" value="{{ old('km') }}" class="form-control form-control-sm text-end @error('km') is-invalid @enderror" inputmode="numeric" required></div>
                    </div>
                    @foreach (['vehicle_id', 'position', 'date', 'km'] as $f)@error($f)<div class="text-danger small">{{ $message }}</div>@enderror @endforeach
                    <button class="btn btn-sm btn-primary mt-2">Fit tyre</button>
                </form>
                <form method="POST" action="{{ route('tyres.retread', $tyre) }}" class="erp-card p-3 mb-3">
                    @csrf
                    <h2 class="h6 fw-bold">Record a retread</h2>
                    <div class="input-group input-group-sm">
                        <span class="input-group-text">Cost</span>
                        <input name="cost" class="form-control text-end @error('cost') is-invalid @enderror" inputmode="decimal" aria-label="Retreading cost" required>
                        <button class="btn btn-outline-primary">Save</button>
                    </div>
                    @error('cost')<div class="text-danger small">{{ $message }}</div>@enderror @error('retread')<div class="text-danger small">{{ $message }}</div>@enderror
                </form>
            @endcan
            @can('transport.delete')
                <form method="POST" action="{{ route('tyres.scrap', $tyre) }}" class="erp-card p-3 mb-3" data-confirm="Scrap tyre {{ $tyre->serial_no }}?">
                    @csrf
                    <h2 class="h6 fw-bold">Scrap</h2>
                    <div class="row g-2">
                        <div class="col-5"><input type="date" name="date" value="{{ now()->toDateString() }}" class="form-control form-control-sm" aria-label="Scrap date" required></div>
                        <div class="col-7"><input name="reason" class="form-control form-control-sm" placeholder="Reason" maxlength="255" aria-label="Reason"></div>
                    </div>
                    @error('scrap')<div class="text-danger small">{{ $message }}</div>@enderror
                    <button class="btn btn-sm btn-outline-danger mt-2">Scrap tyre</button>
                </form>
            @endcan
        @elseif ($current)
            @can('transport.edit')
                <form method="POST" action="{{ route('tyres.remove', $current) }}" class="erp-card p-3 mb-3">
                    @csrf
                    <h2 class="h6 fw-bold">Remove from {{ $current->vehicle->number }}</h2>
                    <p class="small text-secondary">Fitted at {{ number_format($current->fitted_km) }} km on {{ $current->fitted_on->format('d-m-Y') }}.</p>
                    <div class="row g-2">
                        <div class="col-6"><label for="rm_date" class="form-label small">Date</label><input id="rm_date" type="date" name="date" value="{{ old('date', now()->toDateString()) }}" class="form-control form-control-sm @error('date') is-invalid @enderror" required></div>
                        <div class="col-6"><label for="rm_km" class="form-label small">Odometer (km)</label><input id="rm_km" name="km" value="{{ old('km', $odometers[$current->vehicle_id] ?? '') }}" class="form-control form-control-sm text-end @error('km') is-invalid @enderror" inputmode="numeric" required></div>
                        <div class="col-12"><label for="reason" class="form-label small">Reason</label>
                            <select id="reason" name="reason" class="form-select form-select-sm @error('reason') is-invalid @enderror" required>
                                <option value="">Choose…</option>
                                @foreach (\App\Models\TyreFitment::REASONS as $k => $label)<option value="{{ $k }}" @selected(old('reason') === $k)>{{ $label }}</option>@endforeach
                            </select>
                        </div>
                        <div class="col-12"><div class="form-check"><input class="form-check-input" type="checkbox" name="scrap" value="1" id="scrap"><label class="form-check-label small" for="scrap">Scrap it (otherwise it goes back to stock)</label></div></div>
                    </div>
                    @foreach (['date', 'km', 'reason', 'tyre'] as $f)@error($f)<div class="text-danger small">{{ $message }}</div>@enderror @endforeach
                    <button class="btn btn-sm btn-primary mt-2">Remove tyre</button>
                </form>
            @endcan
        @endif

        @can('transport.edit')
            <form method="POST" action="{{ route('tyres.update', $tyre) }}" class="erp-card p-3">
                @csrf @method('PUT')
                <h2 class="h6 fw-bold">Details</h2>
                @include('transport.tyres._fields', ['tyre' => $tyre])
                <button class="btn btn-sm btn-outline-primary mt-2">Save details</button>
            </form>
        @endcan
    </div>
    <div class="col-12 col-xl-8">
        <div class="erp-card">
            <div class="erp-card-header">History</div>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0 table-stack">
                    <thead class="table-light"><tr><th>Vehicle</th><th>Position</th><th>Fitted</th><th>Removed</th><th>Reason</th><th class="text-end">Km</th></tr></thead>
                    <tbody>
                    @forelse ($tyre->fitments->sortByDesc('fitted_on') as $f)
                        <tr>
                            <td data-label="Vehicle">{{ $f->vehicle->number }}</td>
                            <td data-label="Position">{{ $f->position }}</td>
                            <td data-label="Fitted"><div>{{ $f->fitted_on->format('d-m-Y') }} <span class="small text-secondary">{{ number_format($f->fitted_km) }} km</span></div></td>
                            <td data-label="Removed">@if ($f->removed_on)<div>{{ $f->removed_on->format('d-m-Y') }} <span class="small text-secondary">{{ number_format($f->removed_km) }} km</span></div>@else<span class="badge text-bg-success">On the vehicle</span>@endif</td>
                            <td data-label="Reason">{{ $f->removal_reason ? \App\Models\TyreFitment::REASONS[$f->removal_reason] : '—' }}</td>
                            <td data-label="Km" class="text-end">{{ $f->removed_on ? number_format($f->removed_km - $f->fitted_km) : '—' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="text-secondary text-center py-3">Not fitted yet.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
@endsection
