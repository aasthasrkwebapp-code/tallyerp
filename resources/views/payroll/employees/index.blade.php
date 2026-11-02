@extends('layouts.erp')

@section('title', 'Employees')

@section('content')
<div class="page-header">
    <div>
        <h1>Employees</h1>
        <p class="text-secondary mb-0">Staff on the payroll with their salary structure.</p>
    </div>
    @can('payroll.create')
        <a href="{{ route('employees.create') }}" class="btn btn-primary" data-shortcut="c"><i class="bi bi-plus-lg me-1"></i>New employee</a>
    @endcan
</div>

<form method="GET" class="erp-card p-3 mb-3">
    <div class="row g-2 align-items-end">
        <div class="col-12 col-md-5">
            <label class="form-label small" for="search">Search</label>
            <input id="search" name="search" value="{{ $filters['search'] ?? '' }}" class="form-control form-control-sm" placeholder="Name, code or designation">
        </div>
        <div class="col-6 col-md-3">
            <label class="form-label small" for="department">Department</label>
            <select id="department" name="department" class="form-select form-select-sm">
                <option value="">All</option>
                @foreach ($departments as $d)<option @selected(($filters['department'] ?? '') === $d)>{{ $d }}</option>@endforeach
            </select>
        </div>
        <div class="col-6 col-md-2">
            <div class="form-check">
                <input class="form-check-input" type="checkbox" name="inactive" value="1" id="inactive" @checked($filters['inactive'] ?? false)>
                <label class="form-check-label small" for="inactive">Include inactive</label>
            </div>
        </div>
        <div class="col-12 col-md-2"><button class="btn btn-sm btn-primary w-100">Filter</button></div>
    </div>
</form>

<div class="erp-card">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0 table-stack">
            <thead class="table-light"><tr><th>Code</th><th>Name</th><th>Designation</th><th>Joined</th><th class="text-end">Monthly fixed pay</th><th class="text-end">Actions</th></tr></thead>
            <tbody>
            @forelse ($employees as $e)
                <tr>
                    <td data-label="Code">{{ $e->code }}</td>
                    <td data-label="Name">
                        <a href="{{ route('employees.show', $e) }}" class="fw-semibold">{{ $e->name }}</a>
                        @if ($e->date_of_leaving)<span class="badge text-bg-secondary">Left {{ $e->date_of_leaving->format('d-m-Y') }}</span>@elseif (! $e->is_active)<span class="badge text-bg-secondary">Inactive</span>@endif
                    </td>
                    <td data-label="Designation">{{ $e->designation ?: '—' }}@if ($e->department) <span class="text-secondary small">· {{ $e->department }}</span>@endif</td>
                    <td data-label="Joined">{{ $e->date_of_joining->format('d-m-Y') }}</td>
                    <td data-label="Monthly fixed pay" class="amount">{{ \App\Support\Money::format($e->payHeads->filter(fn ($p) => $p->payHead?->type === 'earning')->sum(fn ($p) => \App\Support\Money::toPaise($p->amount)), paiseInput: true) }}</td>
                    <td data-label="" class="text-end text-nowrap">@can('payroll.edit')<a href="{{ route('employees.edit', $e) }}" class="btn btn-sm btn-outline-secondary">Edit</a>@endcan</td>
                </tr>
            @empty
                <tr><td colspan="6" class="text-secondary text-center py-4">No employees yet.@can('payroll.create') <a href="{{ route('employees.create') }}">Add the first employee</a>.@endcan</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
<div class="mt-3">{{ $employees->links() }}</div>
@endsection
