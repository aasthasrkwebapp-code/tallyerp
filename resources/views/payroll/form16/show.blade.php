@extends('layouts.erp')

@section('title', 'Form 16 '.$employee->name)

@section('content')
@php($m = fn (int $p) => \App\Support\Money::format($p, paiseInput: true))
<div class="page-header">
    <div>
        <h1>Form 16 · {{ $employee->name }}</h1>
        <p class="text-secondary mb-0">FY {{ $year->name }} · {{ $employee->code }} · {{ \App\Models\TaxDeclaration::REGIMES[$regime] }}@if ($age !== null) · age {{ $age }}@endif</p>
    </div>
    <div class="d-flex flex-wrap gap-2 no-print">
        @can('payroll.edit')<a href="{{ route('form16.declaration', [$employee, 'year' => $year->id]) }}" class="btn btn-outline-secondary">Edit declaration</a>@endcan
        <a href="{{ route('form16.show', [$employee, 'year' => $year->id, 'export' => 'pdf']) }}" class="btn btn-primary"><i class="bi bi-file-earmark-pdf me-1"></i>Form 16 PDF</a>
        <a href="{{ route('form16.index', ['year' => $year->id]) }}" class="btn btn-outline-secondary">All employees</a>
    </div>
</div>

@foreach (array_filter([
    ! $employee->pan ? 'The employee has no PAN. Without a PAN, tax must be deducted at 20% or more (section 206AA).' : null,
    ! $company->tan ? 'The company has no TAN.' : null,
    $ignored ? 'Declared '.implode(', ', array_map(fn ($f) => \App\Models\TaxDeclaration::AMOUNTS[$f], $ignored)).' are ignored under the new regime.' : null,
    $balance > 0 ? 'TDS deducted is '.$m($balance).' short of the tax payable. Deduct it from the remaining salary, or the employee pays it with the return.' : null,
]) as $warning)
    <div class="alert alert-warning py-2">{{ $warning }}</div>
@endforeach

<div class="row g-3">
    <div class="col-12 col-xl-5">
        <div class="erp-card mb-3">
            <div class="erp-card-header">Part A · tax deducted by quarter</div>
            <div class="table-responsive">
                <table class="table table-sm mb-0 table-stack">
                    <thead class="table-light"><tr><th>Quarter</th><th>24Q receipt</th><th class="text-end">Amount paid</th><th class="text-end">Tax deducted</th></tr></thead>
                    <tbody>
                    @foreach ($quarters as $q => $label)
                        <tr>
                            <td data-label="Quarter">{{ $label }}</td>
                            <td data-label="24Q receipt">{{ $returns[$q]->receipt_number ?? '—' }}</td>
                            <td data-label="Amount paid" class="amount">{{ $m($salary['quarters'][$q]['paid']) }}</td>
                            <td data-label="Tax deducted" class="amount">{{ $m($salary['quarters'][$q]['tds']) }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                    <tfoot><tr class="row-total"><td colspan="2" data-label="">Total</td><td class="amount" data-label="Amount paid">{{ $m(array_sum(array_column($salary['quarters'], 'paid'))) }}</td><td class="amount" data-label="Tax deducted">{{ $m($tds_this_employer) }}</td></tr></tfoot>
                </table>
            </div>
        </div>
        <p class="small text-secondary">Salary is taken from {{ $salary['months'] }} posted payroll month(s) in the year, plus leave encashment. Draft and cancelled payrolls are left out.</p>
    </div>
    <div class="col-12 col-xl-7">
        <div class="erp-card">
            <div class="erp-card-header">Part B · computation of tax</div>
            <div class="table-responsive">
                @include('payroll.form16.computation', ['tableClass' => 'table table-sm mb-0', 'numClass' => 'text-end text-nowrap'])
            </div>
        </div>
    </div>
</div>
@endsection
