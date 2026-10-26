@extends('layouts.erp')

@section('title', 'Audit Trail')

@section('content')
<div class="page-header">
    <div>
        <h1>Audit trail</h1>
        <p class="text-secondary mb-0">Every change to masters and vouchers, with old and new values.</p>
    </div>
</div>

<form method="GET" class="erp-card p-3 mb-3">
    <div class="row g-2 align-items-end">
        <div class="col-6 col-md-2">
            <label class="form-label small" for="f-event">Event</label>
            <select id="f-event" name="event" class="form-select form-select-sm">
                <option value="">All</option>
                @foreach ($events as $event)<option value="{{ $event }}" @selected(($filters['event'] ?? '') === $event)>{{ $event }}</option>@endforeach
            </select>
        </div>
        <div class="col-6 col-md-2">
            <label class="form-label small" for="f-user">User</label>
            <select id="f-user" name="user_id" class="form-select form-select-sm">
                <option value="">All</option>
                @foreach ($users as $u)<option value="{{ $u->id }}" @selected((int) ($filters['user_id'] ?? 0) === $u->id)>{{ $u->name }}</option>@endforeach
            </select>
        </div>
        <div class="col-6 col-md-2">
            <label class="form-label small" for="f-from">From</label>
            <input id="f-from" type="date" name="from" value="{{ $filters['from'] ?? '' }}" class="form-control form-control-sm">
        </div>
        <div class="col-6 col-md-2">
            <label class="form-label small" for="f-to">To</label>
            <input id="f-to" type="date" name="to" value="{{ $filters['to'] ?? '' }}" class="form-control form-control-sm">
        </div>
        <div class="col-12 col-md-3">
            <label class="form-label small" for="f-q">Search</label>
            <input id="f-q" name="q" value="{{ $filters['q'] ?? '' }}" class="form-control form-control-sm" placeholder="Description contains…">
        </div>
        <div class="col-12 col-md-1"><button class="btn btn-sm btn-primary w-100">Filter</button></div>
    </div>
</form>

<div class="erp-card">
    <div class="table-responsive">
        <table class="table table-sm align-middle mb-0 table-stack">
            <thead class="table-light"><tr><th>When</th><th>User</th><th>Event</th><th>Record</th><th>Description</th><th></th></tr></thead>
            <tbody>
            @forelse ($logs as $log)
                <tr>
                    <td data-label="When" class="text-nowrap">{{ $log->created_at->format('d M Y H:i:s') }}</td>
                    <td data-label="User">{{ $log->user?->name ?? 'System' }}</td>
                    <td data-label="Event"><span class="badge text-bg-{{ ['created' => 'success', 'updated' => 'warning', 'deleted' => 'danger'][$log->event] ?? 'secondary' }}">{{ $log->event }}</span></td>
                    <td data-label="Record">{{ $log->auditable_type ? class_basename($log->auditable_type).' #'.$log->auditable_id : '—' }}</td>
                    <td data-label="Description">{{ Str::limit($log->description, 80) }}</td>
                    <td data-label="" class="text-end"><a href="{{ route('audit-logs.show', $log) }}" class="btn btn-sm btn-link">Details</a></td>
                </tr>
            @empty
                <tr><td colspan="6" class="text-center text-secondary py-4">No audit records match.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
<div class="mt-3">{{ $logs->links() }}</div>
@endsection
