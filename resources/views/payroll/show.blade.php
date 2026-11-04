@extends('layouts.erp')

@section('title', 'Payroll '.$run->month->format('M Y'))

@section('content')
@php
    $sum = fn ($field) => $run->payslips->sum(fn ($p) => (float) $p->$field);
@endphp
<div class="container-fluid py-3">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
        <div>
            <h2 class="h4 mb-1">Payroll Register · {{ $run->month->format('F Y') }}</h2>
            <p class="text-secondary mb-0">{{ $company->name }}</p>
        </div>
        <div class="d-flex flex-wrap gap-2 d-print-none">
            <button type="button" class="btn btn-outline-secondary" onclick="window.print()">Print</button>
            @if ($run->voucher)
                <a href="{{ route('companies.vouchers.show', [$company, $run->voucher]) }}" class="btn btn-outline-primary">Journal #{{ $run->voucher->number }}</a>
            @endif
            <form method="POST" action="{{ route('companies.payroll.destroy', [$company, $run]) }}"
                onsubmit="return confirm('Delete this payroll run and its journal?');">
                @csrf
                @method('DELETE')
                <button class="btn btn-outline-danger">Delete</button>
            </form>
            <a href="{{ route('companies.payroll.index', $company) }}" class="btn btn-outline-secondary">Back</a>
        </div>
    </div>

    @if (session('success'))
        <div class="alert alert-success d-print-none">{{ session('success') }}</div>
    @endif

    <div class="card border-0 shadow-sm">
        <div class="table-responsive">
            <table class="table align-middle mb-0 small">
                <thead class="table-light">
                    <tr>
                        <th>Employee</th>
                        <th class="text-end">Days</th>
                        <th class="text-end">Basic</th>
                        <th class="text-end">HRA</th>
                        <th class="text-end">Other</th>
                        <th class="text-end">Gross</th>
                        <th class="text-end">PF</th>
                        <th class="text-end">ESI</th>
                        <th class="text-end">PT</th>
                        <th class="text-end">Net pay</th>
                        <th class="text-end">Employer PF</th>
                        <th class="text-end">Employer ESI</th>
                        <th class="d-print-none"></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($run->payslips as $slip)
                        <tr>
                            <td class="fw-semibold">{{ $slip->employee->name }}</td>
                            <td class="text-end">{{ (float) $slip->days_payable }}/{{ (float) $slip->days_in_month }}</td>
                            <td class="text-end">{{ inr($slip->basic) }}</td>
                            <td class="text-end">{{ inr($slip->hra) }}</td>
                            <td class="text-end">{{ inr($slip->other_allowances) }}</td>
                            <td class="text-end">{{ inr($slip->gross) }}</td>
                            <td class="text-end">{{ inr($slip->pf_employee) }}</td>
                            <td class="text-end">{{ inr($slip->esi_employee) }}</td>
                            <td class="text-end">{{ inr($slip->professional_tax) }}</td>
                            <td class="text-end fw-semibold">{{ inr($slip->net_pay) }}</td>
                            <td class="text-end">{{ inr($slip->pf_employer) }}</td>
                            <td class="text-end">{{ inr($slip->esi_employer) }}</td>
                            <td class="d-print-none"><a href="{{ route('companies.payroll.payslip', [$company, $run, $slip]) }}">Payslip</a></td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot class="fw-semibold">
                    <tr>
                        <td colspan="2">Total</td>
                        @foreach (['basic', 'hra', 'other_allowances', 'gross', 'pf_employee', 'esi_employee', 'professional_tax', 'net_pay', 'pf_employer', 'esi_employer'] as $field)
                            <td class="text-end">{{ inr($sum($field)) }}</td>
                        @endforeach
                        <td class="d-print-none"></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
</div>
@endsection
