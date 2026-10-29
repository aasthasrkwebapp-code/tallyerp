@extends('layouts.erp')

@section('title', 'Audit Record')

@section('content')
<div class="page-header">
    <div>
        <h1>Audit record #{{ $log->id }}</h1>
        <p class="text-secondary mb-0">{{ $log->event }} · {{ $log->created_at->format('d M Y H:i:s') }} · {{ $log->user?->name ?? 'System' }}</p>
    </div>
    <a href="{{ route('audit-logs.index') }}" class="btn btn-outline-secondary">Back</a>
</div>

<div class="erp-card p-3 mb-3">
    <dl class="row mb-0 small">
        <dt class="col-sm-3">Record</dt><dd class="col-sm-9">{{ $log->auditable_type ? class_basename($log->auditable_type).' #'.$log->auditable_id : '—' }}</dd>
        <dt class="col-sm-3">Description</dt><dd class="col-sm-9">{{ $log->description ?? '—' }}</dd>
        <dt class="col-sm-3">IP address</dt><dd class="col-sm-9">{{ $log->ip_address ?? '—' }}</dd>
        <dt class="col-sm-3">Browser</dt><dd class="col-sm-9 text-break">{{ $log->user_agent ?? '—' }}</dd>
    </dl>
</div>

@php($keys = array_unique(array_merge(array_keys($log->old_values ?? []), array_keys($log->new_values ?? []))))
@if ($keys)
    <div class="erp-card">
        <div class="table-responsive">
            <table class="table table-sm mb-0 table-stack">
                <thead class="table-light"><tr><th>Field</th><th>Old value</th><th>New value</th></tr></thead>
                <tbody>
                @foreach ($keys as $key)
                    @php($old = $log->old_values[$key] ?? null)
                    @php($new = $log->new_values[$key] ?? null)
                    <tr>
                        <td data-label="Field" class="fw-semibold">{{ $key }}</td>
                        <td data-label="Old" class="text-danger text-break">{{ is_array($old) ? json_encode($old) : ($old ?? '—') }}</td>
                        <td data-label="New" class="text-success text-break">{{ is_array($new) ? json_encode($new) : ($new ?? '—') }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </div>
@endif
@endsection
