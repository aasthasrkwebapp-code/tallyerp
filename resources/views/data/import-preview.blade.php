@extends('layouts.erp')

@section('title', 'Check import')

@section('content')
<div class="page-header">
    <div>
        <h1>Check import: {{ strtolower($label) }}</h1>
        <p class="text-secondary mb-0">{{ $pending['name'] }} · {{ $result['total'] }} {{ \Illuminate\Support\Str::plural('record', $result['total']) }}</p>
    </div>
    <a href="{{ route('imports.index', ['type' => $pending['type']]) }}" class="btn btn-outline-secondary">Choose another file</a>
</div>

<div class="row g-3 mb-3">
    <div class="col-4"><div class="erp-card p-3 h-100"><div class="small text-secondary">Ready to import</div><div class="fs-4 fw-semibold text-success">{{ $result['created'] }}</div></div></div>
    <div class="col-4"><div class="erp-card p-3 h-100"><div class="small text-secondary">Skipped (already exist)</div><div class="fs-4 fw-semibold">{{ $result['skipped'] }}</div></div></div>
    <div class="col-4"><div class="erp-card p-3 h-100"><div class="small text-secondary">Problems</div><div class="fs-4 fw-semibold {{ $result['errors'] ? 'text-danger' : '' }}">{{ count($result['errors']) }}</div></div></div>
</div>

@if ($result['errors'])
    <div class="alert alert-danger">Nothing has been imported. Correct the rows below in your file and upload it again.
        <a href="{{ route('imports.errors') }}" class="alert-link ms-1"><i class="bi bi-download"></i> Download the error report</a></div>
    <div class="erp-card mb-3">
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0 table-stack">
                <thead class="table-light"><tr><th style="width: 6rem">Row</th><th>Problem</th></tr></thead>
                <tbody>
                @foreach (array_slice($result['errors'], 0, 300) as $e)
                    <tr><td data-label="Row">{{ $e['row'] }}</td><td data-label="Problem">{{ $e['message'] }}</td></tr>
                @endforeach
                </tbody>
            </table>
        </div>
        @if (count($result['errors']) > 300)<div class="p-2 small text-secondary">Showing the first 300 problems; the error report has them all.</div>@endif
    </div>
@else
    <form method="POST" action="{{ route('imports.confirm') }}" class="erp-card p-3 mb-3" data-confirm="Import {{ $result['created'] }} {{ strtolower($label) }} now?">
        @csrf
        <p class="mb-2"><i class="bi bi-check-circle text-success"></i> Every row is valid.@if ($result['skipped']) {{ $result['skipped'] }} {{ \Illuminate\Support\Str::plural('row', $result['skipped']) }} that already exist will be left as they are.@endif</p>
        <button class="btn btn-primary" @disabled($result['created'] === 0)>Import {{ $result['created'] }} {{ strtolower($label) }}</button>
    </form>
@endif

@if ($result['skipped_rows'])
    <div class="erp-card">
        <div class="erp-card-header">Skipped</div>
        <ul class="list-group list-group-flush small">
            @foreach (array_slice($result['skipped_rows'], 0, 100) as $s)
                <li class="list-group-item">Row {{ $s['row'] }}: {{ $s['message'] }}</li>
            @endforeach
        </ul>
    </div>
@endif
@endsection
