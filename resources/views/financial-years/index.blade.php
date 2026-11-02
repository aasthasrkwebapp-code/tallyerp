@extends('layouts.erp')

@section('title', 'Financial Years')

@section('content')
<div class="page-header">
    <div>
        <h1>Financial years &amp; period locks</h1>
        <p class="text-secondary mb-0">Locked years and periods reject new, altered or cancelled vouchers.</p>
    </div>
    @can('periods.create')
        <form method="POST" action="{{ route('financial-years.store') }}">
            @csrf
            <button class="btn btn-primary"><i class="bi bi-plus-lg me-1"></i>Open next year</button>
        </form>
    @endcan
</div>

@foreach ($years as $year)
    <div class="erp-card mb-3">
        <div class="erp-card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
            <div>
                FY {{ $year->name }}
                <span class="text-secondary fw-normal small">{{ $year->start_date->format('d M Y') }} – {{ $year->end_date->format('d M Y') }}</span>
                @if ($year->is_current)<span class="badge text-bg-success ms-1">Current</span>@endif
                @if ($year->is_locked)<span class="badge text-bg-danger ms-1"><i class="bi bi-lock-fill"></i> Locked</span>@endif
            </div>
            <div class="d-flex gap-2">
                @if (! $year->is_current)
                    @can('periods.create')
                        <form method="POST" action="{{ route('financial-years.current', $year) }}">@csrf<button class="btn btn-sm btn-outline-primary">Make current</button></form>
                    @endcan
                @endif
                @can('periods.lock')
                    <form method="POST" action="{{ route('financial-years.lock', $year) }}" data-confirm="{{ $year->is_locked ? 'Unlock' : 'Lock' }} FY {{ $year->name }}?">
                        @csrf
                        <button class="btn btn-sm {{ $year->is_locked ? 'btn-outline-success' : 'btn-outline-danger' }}">
                            <i class="bi bi-{{ $year->is_locked ? 'unlock' : 'lock' }}"></i> {{ $year->is_locked ? 'Unlock year' : 'Lock year' }}
                        </button>
                    </form>
                @endcan
            </div>
        </div>
        <div class="p-3">
            <div class="row g-2">
                @foreach ($year->periods as $period)
                    <div class="col-6 col-sm-4 col-lg-3 col-xxl-2">
                        <div class="border rounded p-2 d-flex justify-content-between align-items-center {{ $period->is_locked ? 'bg-danger-subtle' : '' }}">
                            <span class="small fw-semibold">{{ $period->name }}</span>
                            @can('periods.lock')
                                <form method="POST" action="{{ route('accounting-periods.lock', $period) }}">
                                    @csrf
                                    <button class="btn btn-sm btn-link p-0" aria-label="{{ $period->is_locked ? 'Unlock' : 'Lock' }} {{ $period->name }}">
                                        <i class="bi bi-{{ $period->is_locked ? 'lock-fill text-danger' : 'unlock text-secondary' }}"></i>
                                    </button>
                                </form>
                            @else
                                <i class="bi bi-{{ $period->is_locked ? 'lock-fill text-danger' : 'unlock text-secondary' }}"></i>
                            @endcan
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    </div>
@endforeach
@endsection
