@extends('layouts.erp')

@section('title', 'Payroll')

@section('content')
<div class="container-fluid py-3">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
        <div>
            <h2 class="h4 mb-1">Payroll</h2>
            <p class="text-secondary mb-0">{{ $company->name }}</p>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <a href="{{ route('companies.payroll.create', $company) }}" class="btn btn-primary">+ Run Payroll</a>
            <a href="{{ route('companies.employees.index', $company) }}" class="btn btn-outline-primary">Employees</a>
        </div>
    </div>

    @if (session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif

    <div class="card border-0 shadow-sm">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Month</th>
                        <th class="text-end">Employees</th>
                        <th class="text-end">Gross</th>
                        <th class="text-end">Deductions</th>
                        <th class="text-end">Net pay</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($runs as $run)
                        <tr>
                            <td><a href="{{ route('companies.payroll.show', [$company, $run]) }}">{{ $run->month->format('F Y') }}</a></td>
                            <td class="text-end">{{ $run->payslips_count }}</td>
                            <td class="text-end">{{ inr($run->payslips->sum(fn ($p) => (float) $p->gross)) }}</td>
                            <td class="text-end">{{ inr($run->payslips->sum(fn ($p) => $p->totalDeductions())) }}</td>
                            <td class="text-end fw-semibold">{{ inr($run->payslips->sum(fn ($p) => (float) $p->net_pay)) }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="text-center text-secondary py-4">No payroll has been run yet.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
