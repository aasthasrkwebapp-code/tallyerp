@extends('layouts.erp')

@section('title', 'Run Payroll')

@section('content')
<div class="container-fluid py-3">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
        <div>
            <h2 class="h4 mb-1">Run Payroll</h2>
            <p class="text-secondary mb-0">{{ $company->name }} · FY {{ $company->financial_year }}</p>
        </div>
        <a href="{{ route('companies.payroll.index', $company) }}" class="btn btn-outline-secondary">Back to Payroll</a>
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

    <form method="GET" class="row g-2 mb-3">
        <div class="col-8 col-md-3">
            <input type="month" name="month" value="{{ $month->format('Y-m') }}" class="form-control" aria-label="Month">
        </div>
        <div class="col-4 col-md-2 d-grid"><button class="btn btn-outline-primary">Change month</button></div>
    </form>

    @if ($employees->isEmpty())
        <div class="alert alert-warning">
            No active employees. <a href="{{ route('companies.employees.create', $company) }}">Add an employee</a>
        </div>
    @else
        <form method="POST" action="{{ route('companies.payroll.store', $company) }}">
            @csrf
            <input type="hidden" name="month" value="{{ $month->format('Y-m') }}">

            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white fw-semibold">{{ $month->format('F Y') }} · {{ $month->daysInMonth }} days</div>
                <div class="table-responsive">
                    <table class="table align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Employee</th>
                                <th class="text-end">Monthly gross</th>
                                <th style="width: 160px">Payable days</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($employees as $employee)
                                <tr>
                                    <td>
                                        <div class="fw-semibold">{{ $employee->name }}</div>
                                        <small class="text-secondary">{{ $employee->designation }}</small>
                                    </td>
                                    <td class="text-end">{{ inr($employee->monthlyGross()) }}</td>
                                    <td>
                                        <input type="number" step="0.5" min="0" max="{{ $month->daysInMonth }}"
                                            name="days[{{ $employee->id }}]" value="{{ old('days.'.$employee->id, $month->daysInMonth) }}"
                                            class="form-control text-end">
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="card-footer bg-white d-flex flex-wrap justify-content-between align-items-center gap-2">
                    <small class="text-secondary">Posts one journal on {{ $month->endOfMonth()->format('d-m-Y') }}: salaries and employer contributions against Salary, PF, ESI and PT payable.</small>
                    <button class="btn btn-primary">Calculate &amp; Post</button>
                </div>
            </div>
        </form>
    @endif
</div>
@endsection
