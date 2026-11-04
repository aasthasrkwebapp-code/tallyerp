@extends('layouts.erp')

@section('title', 'Employees')

@section('content')
<div class="container-fluid py-3">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
        <div>
            <h2 class="h4 mb-1">Employees</h2>
            <p class="text-secondary mb-0">{{ $company->name }}</p>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <a href="{{ route('companies.employees.create', $company) }}" class="btn btn-primary">+ Add Employee</a>
            <a href="{{ route('companies.payroll.index', $company) }}" class="btn btn-outline-primary">Payroll</a>
        </div>
    </div>

    @if (session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif
    @if (session('error'))
        <div class="alert alert-danger">{{ session('error') }}</div>
    @endif

    <div class="card border-0 shadow-sm">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Employee</th>
                        <th>Designation</th>
                        <th class="text-end">Basic</th>
                        <th class="text-end">Monthly gross</th>
                        <th>Statutory</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($employees as $employee)
                        <tr class="{{ $employee->is_active ? '' : 'text-secondary' }}">
                            <td>
                                <div class="fw-semibold">{{ $employee->name }}</div>
                                <small class="text-secondary">{{ $employee->code }}{{ $employee->is_active ? '' : ' · Inactive' }}</small>
                            </td>
                            <td>{{ $employee->designation ?: '—' }}</td>
                            <td class="text-end">{{ inr($employee->basic) }}</td>
                            <td class="text-end">{{ inr($employee->monthlyGross()) }}</td>
                            <td class="small">
                                {{ collect(['PF' => $employee->pf_applicable, 'ESI' => $employee->esi_applicable, 'PT' => $employee->pt_applicable])->filter()->keys()->join(', ') ?: '—' }}
                            </td>
                            <td class="text-end">
                                <div class="btn-group btn-group-sm" role="group">
                                    <a href="{{ route('companies.employees.edit', [$company, $employee]) }}" class="btn btn-outline-primary">Edit</a>
                                    <form method="POST" action="{{ route('companies.employees.destroy', [$company, $employee]) }}"
                                        onsubmit="return confirm(@js('Delete employee '.$employee->name.'?'));">
                                        @csrf
                                        @method('DELETE')
                                        <button class="btn btn-outline-danger btn-sm">Delete</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="text-center text-secondary py-4">
                                No employees yet. <a href="{{ route('companies.employees.create', $company) }}">Add the first one</a>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
