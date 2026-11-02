@extends('layouts.erp')

@section('title', 'Tyres')

@php($p = fn ($paise) => \App\Support\Money::format($paise, paiseInput: true))
@php($badge = ['stock' => 'text-bg-secondary', 'fitted' => 'text-bg-success', 'scrapped' => 'text-bg-dark'])

@section('content')
<div class="page-header">
    <div>
        <h1>Tyres</h1>
        <p class="text-secondary mb-0">Each tyre by serial number: where it is fitted, kilometres run, retreads and cost per km</p>
    </div>
    <div class="d-flex flex-wrap gap-2 no-print">
        <a href="{{ request()->fullUrlWithQuery(['export' => 'csv']) }}" class="btn btn-outline-secondary"><i class="bi bi-filetype-csv"></i> CSV</a>
        <a href="{{ request()->fullUrlWithQuery(['export' => 'xlsx']) }}" class="btn btn-outline-secondary"><i class="bi bi-file-earmark-excel"></i> Excel</a>
    </div>
</div>

<div class="row g-3 mb-3">
    <div class="col-4 col-md-3"><a href="{{ route('tyres.index', ['status' => 'fitted']) }}" class="erp-card stat-card d-block text-decoration-none text-body"><div class="stat-label">Fitted</div><div class="stat-value">{{ $counts['fitted'] }}</div></a></div>
    <div class="col-4 col-md-3"><a href="{{ route('tyres.index', ['status' => 'stock']) }}" class="erp-card stat-card d-block text-decoration-none text-body"><div class="stat-label">In stock</div><div class="stat-value">{{ $counts['stock'] }}</div></a></div>
    <div class="col-4 col-md-3"><a href="{{ route('tyres.index', ['status' => 'due']) }}" class="erp-card stat-card d-block text-decoration-none text-body"><div class="stat-label">Life reached</div><div class="stat-value {{ $counts['due'] ? 'text-danger' : '' }}">{{ $counts['due'] }}</div></a></div>
</div>

<form method="GET" class="erp-card p-3 mb-3 no-print">
    <div class="row g-2 align-items-end">
        <div class="col-6 col-sm-auto">
            <label class="form-label small" for="status">Show</label>
            <select id="status" name="status" class="form-select form-select-sm">
                @foreach (['all' => 'All tyres', 'due' => 'Expected life reached'] + \App\Models\Tyre::STATUSES as $k => $label)<option value="{{ $k }}" @selected($status === $k)>{{ $label }}</option>@endforeach
            </select>
        </div>
        <div class="col-6 col-sm-auto">
            <label class="form-label small" for="vehicle">Fitted to</label>
            <select id="vehicle" name="vehicle" class="form-select form-select-sm">
                <option value="">Any vehicle</option>
                @foreach ($vehicles as $veh)<option value="{{ $veh->id }}" @selected((int) ($filters['vehicle'] ?? 0) === $veh->id)>{{ $veh->number }}{{ $veh->is_active ? '' : ' (inactive)' }}</option>@endforeach
            </select>
        </div>
        <div class="col-12 col-sm-auto"><button class="btn btn-sm btn-primary w-100">Show</button></div>
    </div>
</form>

<div class="row g-3">
    <div class="col-12 col-xl-8">
        <div class="erp-card">
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0 table-stack">
                    <thead class="table-light"><tr><th>Serial no</th><th>Tyre</th><th>Status</th><th>On</th><th class="text-end">Km run</th><th class="text-end">Cost</th><th class="text-end">Per km</th></tr></thead>
                    <tbody>
                    @forelse ($rows as $r)
                        <tr>
                            <td data-label="Serial no"><a href="{{ route('tyres.show', $r->tyre) }}" class="fw-semibold">{{ $r->tyre->serial_no }}</a></td>
                            <td data-label="Tyre"><div>{{ trim($r->tyre->brand.' '.$r->tyre->size) ?: '—' }}@if ($r->tyre->retreads) <span class="small text-secondary">· {{ $r->tyre->retreads }} {{ \Illuminate\Support\Str::plural('retread', $r->tyre->retreads) }}</span>@endif</div></td>
                            <td data-label="Status"><div><span class="badge {{ $badge[$r->tyre->status] }}">{{ \App\Models\Tyre::STATUSES[$r->tyre->status] }}</span>@if ($r->due) <span class="badge text-bg-danger">Life reached</span>@endif</div></td>
                            <td data-label="On">{{ $r->current ? $r->current->vehicle->number.' · '.$r->current->position : '—' }}</td>
                            <td data-label="Km run" class="text-end">{{ number_format($r->km) }}@if ($r->tyre->expected_km)<div class="small text-secondary">of {{ number_format($r->tyre->expected_km) }}</div>@endif</td>
                            <td data-label="Cost" class="amount">{{ $p($r->cost) }}</td>
                            <td data-label="Per km" class="amount">{{ $r->per_km !== null ? $p($r->per_km) : '—' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="text-secondary text-center py-3">No tyres here yet.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        <p class="small text-secondary mt-2">Kilometres come from the odometer readings entered when a tyre is fitted and removed. A tyre still fitted counts up to the vehicle's latest reading on its trips.</p>
    </div>
    <div class="col-12 col-xl-4">
        @can('transport.create')
            <form method="POST" action="{{ route('tyres.store') }}" class="erp-card p-3">
                @csrf
                <h2 class="h6 fw-bold">Add a tyre</h2>
                @include('transport.tyres._fields', ['tyre' => null])
                <button class="btn btn-primary btn-sm mt-2">Add to stock</button>
            </form>
        @endcan
    </div>
</div>
@endsection
