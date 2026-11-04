@extends('layouts.erp')

@section('title', $employee->exists ? 'Edit Employee' : 'Add Employee')

@section('content')
<div class="container-fluid py-3">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
        <div>
            <h2 class="h4 mb-1">{{ $employee->exists ? 'Edit Employee' : 'Add Employee' }}</h2>
            <p class="text-secondary mb-0">{{ $company->name }}</p>
        </div>
        <a href="{{ route('companies.employees.index', $company) }}" class="btn btn-outline-secondary">Back to Employees</a>
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

    <form method="POST"
        action="{{ $employee->exists ? route('companies.employees.update', [$company, $employee]) : route('companies.employees.store', $company) }}">
        @csrf
        @if ($employee->exists)
            @method('PUT')
        @endif

        <div class="card border-0 shadow-sm">
            <div class="card-body p-3 p-md-4">
                <div class="row g-3">
                    <div class="col-12 col-md-6">
                        <label class="form-label">Name *</label>
                        <input name="name" value="{{ old('name', $employee->name) }}" class="form-control" required maxlength="255">
                    </div>
                    <div class="col-6 col-md-3">
                        <label class="form-label">Employee code</label>
                        <input name="code" value="{{ old('code', $employee->code) }}" class="form-control" maxlength="30">
                    </div>
                    <div class="col-6 col-md-3">
                        <label class="form-label">Joined on</label>
                        <input type="date" name="joined_on" value="{{ old('joined_on', $employee->joined_on?->toDateString()) }}" class="form-control">
                    </div>
                    <div class="col-12 col-md-6">
                        <label class="form-label">Designation</label>
                        <input name="designation" value="{{ old('designation', $employee->designation) }}" class="form-control" maxlength="255">
                    </div>
                    <div class="col-6 col-md-3">
                        <label class="form-label">PAN</label>
                        <input name="pan" value="{{ old('pan', $employee->pan) }}" class="form-control text-uppercase" maxlength="10">
                    </div>
                    <div class="col-6 col-md-3">
                        <label class="form-label">UAN (PF)</label>
                        <input name="uan" value="{{ old('uan', $employee->uan) }}" class="form-control" maxlength="20">
                    </div>

                    <div class="col-12"><h3 class="h6 mt-2 mb-0">Monthly salary</h3></div>
                    <div class="col-4">
                        <label class="form-label">Basic *</label>
                        <input type="number" step="0.01" min="0" name="basic" value="{{ old('basic', (float) $employee->basic ?: '') }}" class="form-control text-end" required>
                    </div>
                    <div class="col-4">
                        <label class="form-label">HRA</label>
                        <input type="number" step="0.01" min="0" name="hra" value="{{ old('hra', (float) $employee->hra ?: '') }}" class="form-control text-end">
                    </div>
                    <div class="col-4">
                        <label class="form-label">Other allowances</label>
                        <input type="number" step="0.01" min="0" name="other_allowances" value="{{ old('other_allowances', (float) $employee->other_allowances ?: '') }}" class="form-control text-end">
                    </div>

                    <div class="col-12">
                        <div class="d-flex flex-wrap gap-4">
                            @foreach ([
                                'pf_applicable' => 'PF (12% of basic up to '.inr(\App\Support\Payroll::PF_WAGE_CEILING, 0).')',
                                'esi_applicable' => 'ESI (if gross ≤ '.inr(\App\Support\Payroll::ESI_LIMIT, 0).')',
                                'pt_applicable' => 'Professional tax',
                                'is_active' => 'Active',
                            ] as $flag => $label)
                                <div class="form-check">
                                    <input type="hidden" name="{{ $flag }}" value="0">
                                    <input type="checkbox" class="form-check-input" id="{{ $flag }}" name="{{ $flag }}" value="1"
                                        @checked(old($flag, $employee->$flag))>
                                    <label class="form-check-label" for="{{ $flag }}">{{ $label }}</label>
                                </div>
                            @endforeach
                        </div>
                    </div>
                </div>
            </div>
            <div class="card-footer bg-white text-end">
                <button class="btn btn-primary">Save Employee</button>
            </div>
        </div>
    </form>
</div>
@endsection
