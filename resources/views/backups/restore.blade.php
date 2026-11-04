@extends('layouts.erp')

@section('title', 'Restore Backup')

@section('content')
<div class="container-fluid py-3">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
        <div>
            <h2 class="h4 mb-1">Restore Backup</h2>
            <p class="text-secondary mb-0">Creates a new company from a backup file. Your existing companies are not changed.</p>
        </div>
        <a href="{{ route('companies.index') }}" class="btn btn-outline-secondary">Back to Companies</a>
    </div>

    @if ($errors->any())
        <div class="alert alert-danger">
            <ul class="mb-0">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="POST" action="{{ route('companies.restore.store') }}" enctype="multipart/form-data">
        @csrf
        <div class="card border-0 shadow-sm" style="max-width: 640px">
            <div class="card-body">
                <div class="mb-3">
                    <label class="form-label" for="backup">Backup file (.json) *</label>
                    <input type="file" id="backup" name="backup" accept=".json,application/json" class="form-control" required>
                    <div class="form-text">Made with "Backup" inside a company.</div>
                </div>
                <div>
                    <label class="form-label" for="name">Name for the restored company</label>
                    <input id="name" name="name" value="{{ old('name') }}" class="form-control" maxlength="255" placeholder="Leave blank to use the original name + (restored)">
                </div>
            </div>
            <div class="card-footer bg-white text-end">
                <button class="btn btn-primary">Restore</button>
            </div>
        </div>
    </form>

    <p class="small text-secondary mt-3">
        A backup holds the books: groups, ledgers, vouchers, GST, stock, TDS, payroll, cost centres and budgets.
        Shared users and the audit log are not included.
    </p>
</div>
@endsection
