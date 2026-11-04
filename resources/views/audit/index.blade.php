@extends('layouts.erp')

@section('title', 'Audit Log')

@section('content')
<div class="container-fluid py-3">
    @include('reports._header', ['title' => 'Audit Log', 'subtitle' => 'Who changed what'])

    <form method="GET" class="row g-2 mb-3 d-print-none">
        <div class="col-6 col-md-3">
            <select name="action" class="form-select" aria-label="Action">
                <option value="">All actions</option>
                @foreach (['created', 'updated', 'deleted'] as $action)
                    <option value="{{ $action }}" @selected(request('action') === $action)>{{ ucfirst($action) }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-6 col-md-3">
            <select name="type" class="form-select" aria-label="Record type">
                <option value="">All records</option>
                @foreach ($types as $type)
                    <option value="{{ $type }}" @selected(request('type') === $type)>{{ \Illuminate\Support\Str::headline($type) }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-12 col-md-2 d-grid"><button class="btn btn-outline-primary">Filter</button></div>
    </form>

    <div class="card border-0 shadow-sm">
        <div class="table-responsive">
            <table class="table align-middle mb-0">
                <thead class="table-light">
                    <tr><th>When</th><th>User</th><th>Action</th><th>Record</th><th>Details</th></tr>
                </thead>
                <tbody>
                    @forelse ($logs as $log)
                        <tr>
                            <td class="text-nowrap small">{{ $log->created_at->format('d-m-Y H:i') }}</td>
                            <td>{{ $log->user?->name ?? 'System' }}</td>
                            <td>
                                <span class="badge {{ ['created' => 'text-bg-success', 'updated' => 'text-bg-primary', 'deleted' => 'text-bg-danger'][$log->action] ?? 'text-bg-secondary' }}">
                                    {{ ucfirst($log->action) }}
                                </span>
                            </td>
                            <td>{{ $log->summary }}</td>
                            <td class="small text-secondary">
                                @if ($log->action === 'updated' && $log->changes)
                                    @foreach ($log->changes as $field => $change)
                                        <div>
                                            {{ \Illuminate\Support\Str::headline($field) }}:
                                            <span class="text-decoration-line-through">{{ \Illuminate\Support\Str::limit(is_scalar($change['from'] ?? null) ? (string) $change['from'] : json_encode($change['from'] ?? null), 40) }}</span>
                                            → {{ \Illuminate\Support\Str::limit(is_scalar($change['to'] ?? null) ? (string) $change['to'] : json_encode($change['to'] ?? null), 40) }}
                                        </div>
                                    @endforeach
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="text-center text-secondary py-4">Nothing recorded yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-3">{{ $logs->links() }}</div>
</div>
@endsection
