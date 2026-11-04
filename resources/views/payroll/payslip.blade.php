@extends('layouts.erp')

@section('title', 'Payslip')

@section('content')
<div class="container-fluid py-3">
    <div class="d-flex flex-wrap justify-content-end gap-2 mb-3 d-print-none">
        <button type="button" class="btn btn-outline-secondary" onclick="window.print()">Print</button>
        <a href="{{ route('companies.payroll.show', [$company, $run]) }}" class="btn btn-outline-secondary">Back to Register</a>
    </div>

    <div class="card border-0 shadow-sm mx-auto" style="max-width: 760px">
        <div class="card-body p-4">
            <div class="text-center mb-3">
                <div class="h5 mb-0">{{ $company->legal_name ?: $company->name }}</div>
                @if ($company->address)<div class="small text-secondary">{{ $company->address }}</div>@endif
                <div class="fw-semibold mt-2">Payslip for {{ $run->month->format('F Y') }}</div>
            </div>

            <div class="row small mb-3">
                <div class="col-6">
                    <div><span class="text-secondary">Employee:</span> {{ $payslip->employee->name }}</div>
                    <div><span class="text-secondary">Code:</span> {{ $payslip->employee->code ?: '—' }}</div>
                    <div><span class="text-secondary">Designation:</span> {{ $payslip->employee->designation ?: '—' }}</div>
                </div>
                <div class="col-6">
                    <div><span class="text-secondary">PAN:</span> {{ $payslip->employee->pan ?: '—' }}</div>
                    <div><span class="text-secondary">UAN:</span> {{ $payslip->employee->uan ?: '—' }}</div>
                    <div><span class="text-secondary">Days paid:</span> {{ (float) $payslip->days_payable }} of {{ (float) $payslip->days_in_month }}</div>
                </div>
            </div>

            <div class="row g-3">
                <div class="col-12 col-md-6">
                    <table class="table table-sm table-bordered mb-0">
                        <thead class="table-light"><tr><th>Earnings</th><th class="text-end">Amount</th></tr></thead>
                        <tbody>
                            <tr><td>Basic</td><td class="text-end">{{ inr($payslip->basic) }}</td></tr>
                            <tr><td>HRA</td><td class="text-end">{{ inr($payslip->hra) }}</td></tr>
                            <tr><td>Other allowances</td><td class="text-end">{{ inr($payslip->other_allowances) }}</td></tr>
                        </tbody>
                        <tfoot class="fw-semibold"><tr><td>Gross earnings</td><td class="text-end">{{ inr($payslip->gross) }}</td></tr></tfoot>
                    </table>
                </div>
                <div class="col-12 col-md-6">
                    <table class="table table-sm table-bordered mb-0">
                        <thead class="table-light"><tr><th>Deductions</th><th class="text-end">Amount</th></tr></thead>
                        <tbody>
                            <tr><td>Provident Fund</td><td class="text-end">{{ inr($payslip->pf_employee) }}</td></tr>
                            <tr><td>ESI</td><td class="text-end">{{ inr($payslip->esi_employee) }}</td></tr>
                            <tr><td>Professional tax</td><td class="text-end">{{ inr($payslip->professional_tax) }}</td></tr>
                        </tbody>
                        <tfoot class="fw-semibold"><tr><td>Total deductions</td><td class="text-end">{{ inr($payslip->totalDeductions()) }}</td></tr></tfoot>
                    </table>
                </div>
            </div>

            <div class="alert alert-light border text-center mt-3 mb-0">
                Net pay: <strong class="fs-5">₹ {{ inr($payslip->net_pay) }}</strong>
            </div>
        </div>
    </div>
</div>
@endsection
