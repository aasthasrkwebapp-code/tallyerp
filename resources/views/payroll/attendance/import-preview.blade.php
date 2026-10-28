@extends('layouts.erp')

@section('title', 'Import Attendance')

@section('content')
<div class="page-header">
    <div>
        <h1>Import attendance: preview</h1>
        <p class="text-secondary mb-0">{{ $name }} · {{ $result['layout'] === 'grid' ? 'register layout' : 'list layout' }} · {{ $result['rows'] }} {{ \Illuminate\Support\Str::plural('row', $result['rows']) }} · {{ $result['days'] }} {{ \Illuminate\Support\Str::plural('day', $result['days']) }} for {{ $result['employees'] }} {{ \Illuminate\Support\Str::plural('employee', $result['employees']) }}</p>
    </div>
    <a href="{{ route('attendance.import') }}" class="btn btn-outline-secondary">Upload another file</a>
</div>

@if ($result['errors'])
    <div class="alert alert-danger">
        <div class="fw-semibold mb-1">{{ count($result['errors']) }} {{ \Illuminate\Support\Str::plural('problem', count($result['errors'])) }}. Nothing has been imported; fix the file and upload it again.</div>
        <ul class="mb-0 small">
            @foreach (array_slice($result['errors'], 0, 200) as $e)<li>@if ($e['row'])Row {{ $e['row'] }}: @endif{{ $e['message'] }}</li>@endforeach
            @if (count($result['errors']) > 200)<li>and {{ count($result['errors']) - 200 }} more.</li>@endif
        </ul>
    </div>
@elseif ($summary->isEmpty())
    <div class="erp-card p-4 text-secondary">The file has nothing to import.</div>
@else
    <form method="POST" action="{{ route('attendance.import.confirm') }}" class="mb-3">
        @csrf
        <input type="hidden" name="payload" value="{{ json_encode(['entries' => $result['entries'], 'overtime' => $result['overtime']]) }}">
        <button class="btn btn-primary" data-shortcut="s">Import {{ $result['days'] }} {{ \Illuminate\Support\Str::plural('day', $result['days']) }}</button>
    </form>
@endif

@if ($summary->isNotEmpty())
<div class="erp-card">
    <div class="table-responsive">
        <table class="table table-sm align-middle mb-0 table-stack">
            <thead class="table-light"><tr><th>Month</th><th>Employee</th><th class="text-end">Days in file</th><th>Entries</th><th class="text-end">Overtime hours</th></tr></thead>
            <tbody>
            @foreach ($summary as $s)
                <tr>
                    <td data-label="Month">{{ \Illuminate\Support\Carbon::createFromFormat('Y-m-d', $s->month.'-01')->format('M Y') }}</td>
                    <td data-label="Employee">{{ $s->employee?->name }} <span class="text-secondary small">{{ $s->employee?->code }}</span></td>
                    <td data-label="Days in file" class="text-end">{{ $s->days }}</td>
                    <td data-label="Entries">@foreach ($s->counts as $label => $n)<span class="badge text-bg-light border me-1">{{ $label }} × {{ $n }}</span>@endforeach</td>
                    <td data-label="Overtime hours" class="text-end">{{ $s->overtime !== null ? \App\Support\Decimal::trim($s->overtime) : '—' }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
</div>
<p class="small text-secondary mt-2">Overtime shown replaces the month's overtime for that employee. Days not in the file keep what the register has.</p>
@endif
@endsection
