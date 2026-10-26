@extends('layouts.erp')

@section('title', 'Dashboard')

@section('content')
<div class="page-header">
    <div>
        <h1>{{ $company->name }}</h1>
        <p class="text-secondary mb-0">
            @if ($year) Financial year {{ $year->name }} ({{ $year->start_date->format('d M Y') }} – {{ $year->end_date->format('d M Y') }}) @endif
        </p>
    </div>
</div>

@includeWhen(View::exists('dashboard._figures'), 'dashboard._figures')

<h2 class="h6 fw-bold mt-4 mb-3">Quick actions</h2>
<div class="row g-3">
    @foreach (config('erp.menu') as $item)
        @continue(isset($item['heading']) || $item['route'] === 'dashboard' || ! Route::has($item['route']))
        @continue(isset($item['can']) && auth()->user()->cannot($item['can']))
        <div class="col-6 col-md-4 col-xl-3">
            <a href="{{ route($item['route'], $item['params'] ?? []) }}" class="erp-card d-flex align-items-center gap-3 p-3 text-decoration-none text-body h-100">
                <i class="bi bi-{{ $item['icon'] }} fs-4" style="color: var(--erp-accent)" aria-hidden="true"></i>
                <span class="fw-semibold small">{{ $item['label'] }}</span>
            </a>
        </div>
    @endforeach
</div>
@endsection
