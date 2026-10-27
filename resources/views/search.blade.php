@extends('layouts.erp')

@section('title', $term !== '' ? 'Search: '.$term : 'Search')

@section('content')
<div class="page-header">
    <div>
        <h1>Search</h1>
        <p class="text-secondary mb-0">Ledgers, stock items, batches and serial numbers, invoices, vouchers, orders, notes, employees and trips in {{ $currentCompany->name }}.</p>
    </div>
</div>

<form method="GET" action="{{ route('search') }}" class="mb-3" role="search" style="max-width: 640px">
    <div class="input-group">
        <input type="search" name="q" value="{{ $term }}" class="form-control @error('q') is-invalid @enderror" placeholder="Name, number, reference, item code, serial…" aria-label="Search" maxlength="100" autofocus>
        <button class="btn btn-primary"><i class="bi bi-search me-1"></i>Search</button>
        @error('q')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
</form>

@if ($term === '')
    <p class="text-secondary">Type at least two characters. Press <kbd>/</kbd> anywhere to jump to search.</p>
@elseif (mb_strlen($term) < 2)
    <p class="text-secondary">Type at least two characters.</p>
@elseif ($groups->isEmpty())
    <div class="erp-card p-4 text-center text-secondary">Nothing matches “{{ $term }}”.</div>
@else
    <div class="row g-3">
        @foreach ($groups as $g)
            <div class="col-12 col-xl-6">
                <div class="erp-card h-100">
                    <div class="erp-card-header d-flex justify-content-between align-items-center">
                        <span><i class="bi {{ $g['icon'] }} me-1"></i>{{ $g['label'] }}</span>
                        <span class="badge text-bg-light">{{ count($g['rows']) }}{{ $g['truncated'] ? '+' : '' }}</span>
                    </div>
                    <div class="list-group list-group-flush">
                        @foreach ($g['rows'] as $r)
                            <a href="{{ $r['url'] }}" class="list-group-item list-group-item-action search-result">
                                <div class="fw-semibold text-break">{{ $r['title'] }}</div>
                                @if ($r['meta'] !== '')<div class="small text-secondary text-break">{{ $r['meta'] }}</div>@endif
                            </a>
                        @endforeach
                    </div>
                    @if ($g['truncated'])
                        <div class="p-2 small text-secondary">
                            @if ($g['more'])<a href="{{ $g['more'] }}">See all matches</a>@else Showing the first {{ \App\Http\Controllers\SearchController::PER_GROUP }}. Type more to narrow the search.@endif
                        </div>
                    @endif
                </div>
            </div>
        @endforeach
    </div>
@endif
@endsection
