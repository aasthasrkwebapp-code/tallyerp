@extends('layouts.erp')

@section('title', 'Backup & Restore')

@php
    $size = fn (int $b) => $b >= 1048576 ? number_format($b / 1048576, 1).' MB' : number_format(max($b, 1) / 1024, 0).' KB';
    $badge = ['completed' => 'success', 'failed' => 'danger', 'restored' => 'primary'];
@endphp

@section('content')
<div class="page-header">
    <div>
        <h1>Backup &amp; restore</h1>
        <p class="text-secondary mb-0">Backups of {{ $company->name }}: ledgers, vouchers, invoices, stock, payroll, transport and settings.</p>
    </div>
    <form method="POST" action="{{ route('backups.store') }}" class="no-print">
        @csrf
        <button class="btn btn-primary"><i class="bi bi-database-down"></i> Back up now</button>
    </form>
</div>

<div class="row g-3 mb-3">
    <div class="col-12 col-lg-6">
        <form method="POST" action="{{ route('backups.schedule') }}" class="erp-card p-3 h-100">
            @csrf @method('PUT')
            <h2 class="h6 fw-bold">Automatic backups</h2>
            <div class="row g-2 align-items-end">
                <div class="col-6">
                    <label for="backup_frequency" class="form-label small">Frequency</label>
                    <select id="backup_frequency" name="backup_frequency" class="form-select">
                        @foreach (['off' => 'Off', 'daily' => 'Daily', 'weekly' => 'Weekly'] as $k => $label)
                            <option value="{{ $k }}" @selected($company->setting('backup_frequency') === $k)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-6">
                    <label for="backup_keep" class="form-label small">Keep the last</label>
                    <div class="input-group">
                        <input id="backup_keep" name="backup_keep" type="number" min="1" max="90" value="{{ $company->setting('backup_keep') }}" class="form-control @error('backup_keep') is-invalid @enderror">
                        <span class="input-group-text">backups</span>
                    </div>
                </div>
                <div class="col-12"><button class="btn btn-outline-primary">Save schedule</button></div>
            </div>
            <p class="small text-secondary mt-2 mb-0">Runs at 01:30 through the Laravel scheduler (<code>php artisan schedule:run</code> every minute from cron). Each automatic backup is verified after it is made.</p>
        </form>
    </div>
    <div class="col-12 col-lg-6">
        <form method="POST" action="{{ route('backups.upload') }}" enctype="multipart/form-data" class="erp-card p-3 h-100">
            @csrf
            <h2 class="h6 fw-bold">Upload a backup</h2>
            <label for="file" class="form-label small">Backup file (.zip) downloaded from this company</label>
            <div class="input-group">
                <input id="file" name="file" type="file" accept=".zip,application/zip" class="form-control @error('file') is-invalid @enderror" required>
                <button class="btn btn-outline-primary">Upload</button>
            </div>
            @error('file')<div class="text-danger small mt-1">{{ $message }}</div>@enderror
            <p class="small text-secondary mt-2 mb-0">The file is checked when it is uploaded. It can then be restored from the list below.</p>
        </form>
    </div>
</div>

@if ($errors->has('backup') || $errors->has('confirm'))
    <div class="alert alert-danger">@foreach ($errors->get('backup') + $errors->get('confirm') as $e)<div>{{ is_array($e) ? implode(' ', $e) : $e }}</div>@endforeach</div>
@endif

<div class="erp-card">
    <div class="table-responsive">
        <table class="table align-middle mb-0 table-stack">
            <thead class="table-light"><tr><th>Made</th><th>Type</th><th>File</th><th class="text-end">Records</th><th>Status</th><th>Checked</th><th class="no-print"></th></tr></thead>
            <tbody>
            @forelse ($logs as $log)
                <tr>
                    <td data-label="Made">{{ $log->created_at->format('d M Y H:i') }}<div class="small text-secondary">{{ $log->creator?->name ?? 'Scheduler' }}</div></td>
                    <td data-label="Type">{{ \App\Models\BackupLog::TYPES[$log->type] ?? $log->type }}</td>
                    <td data-label="File" class="text-break"><span class="small">{{ $log->file_name }}</span>@if ($log->status !== 'failed')<div class="small text-secondary">{{ $size($log->size) }}</div>@endif</td>
                    <td data-label="Records" class="text-end">{{ number_format($log->totalRows()) }}</td>
                    <td data-label="Status">
                        <span class="badge text-bg-{{ $badge[$log->status] ?? 'secondary' }}">{{ ucfirst($log->status) }}</span>
                        @if ($log->restored_at)<div class="small text-secondary">restored {{ $log->restored_at->format('d M Y H:i') }} by {{ $log->restorer?->name }}</div>@endif
                        @if ($log->error)<div class="small text-danger">{{ \Illuminate\Support\Str::limit($log->error, 160) }}</div>@endif
                    </td>
                    <td data-label="Checked">
                        @if ($log->verified_at)
                            <span class="{{ $log->verified_ok ? 'text-success' : 'text-danger' }}"><i class="bi {{ $log->verified_ok ? 'bi-check-circle' : 'bi-x-circle' }}"></i> {{ $log->verified_ok ? 'Verified' : 'Failed' }}</span>
                            <div class="small text-secondary">{{ $log->verified_at->format('d M Y H:i') }}</div>
                        @else
                            <span class="text-secondary">—</span>
                        @endif
                    </td>
                    <td data-label="" class="text-end no-print">
                        @if ($log->status !== 'failed' && $log->fileExists())
                            <div class="d-flex flex-wrap gap-1 justify-content-end">
                                <a href="{{ route('backups.download', $log) }}" class="btn btn-sm btn-outline-secondary" aria-label="Download {{ $log->file_name }}"><i class="bi bi-download"></i></a>
                                <form method="POST" action="{{ route('backups.verify', $log) }}">@csrf<button class="btn btn-sm btn-outline-secondary">Verify</button></form>
                                <form method="POST" action="{{ route('backups.destroy', $log) }}" data-confirm="Delete this backup file?">@csrf @method('DELETE')<button class="btn btn-sm btn-outline-danger" aria-label="Delete backup"><i class="bi bi-trash"></i></button></form>
                            </div>
                            <details class="mt-2 text-start">
                                <summary class="small text-danger">Restore…</summary>
                                <form method="POST" action="{{ route('backups.restore', $log) }}" class="mt-2" data-confirm="Replace all of {{ $company->name }}'s data with this backup?">
                                    @csrf
                                    <p class="small mb-1">All current vouchers, invoices, ledgers and masters of this company are replaced by the backup. A safety backup is made first.</p>
                                    <label for="confirm-{{ $log->id }}" class="form-label small">Type <strong>{{ $company->name }}</strong> to confirm</label>
                                    <div class="input-group input-group-sm">
                                        <input id="confirm-{{ $log->id }}" name="confirm" class="form-control" autocomplete="off" required>
                                        <button class="btn btn-danger">Restore</button>
                                    </div>
                                </form>
                            </details>
                        @elseif ($log->status !== 'failed')
                            <span class="small text-danger">File missing</span>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="7" class="text-center text-secondary py-4">No backups yet. Use <strong>Back up now</strong> to make the first one.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
<div class="mt-3">{{ $logs->links() }}</div>
<p class="small text-secondary mt-2">Backup files are stored on the server in <code>storage/app/private/backups</code>. Download them regularly and keep copies elsewhere. Users, roles and the audit trail are not part of a company backup. For the whole database use your database server's own backup (for MySQL, <code>mysqldump</code>).</p>
@endsection
