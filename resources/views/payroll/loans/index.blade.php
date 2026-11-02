@extends('layouts.erp')

@section('title', 'Loans & Advances')

@php($p = fn ($paise) => \App\Support\Money::format($paise, paiseInput: true))

@section('content')
<div class="page-header">
    <div>
        <h1>Loans &amp; advances</h1>
        <p class="text-secondary mb-0">Loans and salary advances to employees, recovered in monthly instalments through payroll.</p>
    </div>
    <div class="d-flex flex-wrap gap-2 no-print">
        @can('payroll.create')<a href="{{ route('employee-loans.create') }}" class="btn btn-primary"><i class="bi bi-plus-lg"></i> New loan or advance</a>@endcan
        <a href="{{ request()->fullUrlWithQuery(['export' => 'csv']) }}" class="btn btn-outline-secondary"><i class="bi bi-filetype-csv"></i> CSV</a>
        <a href="{{ request()->fullUrlWithQuery(['export' => 'xlsx']) }}" class="btn btn-outline-secondary"><i class="bi bi-file-earmark-excel"></i> Excel</a>
        <a href="{{ request()->fullUrlWithQuery(['export' => 'pdf']) }}" class="btn btn-outline-secondary"><i class="bi bi-file-earmark-pdf"></i> PDF</a>
    </div>
</div>

<form method="GET" class="erp-card p-3 mb-3 no-print">
    <div class="row g-2 align-items-end">
        <div class="col-12 col-sm-4">
            <label for="employee_id" class="form-label">Employee</label>
            <select id="employee_id" name="employee_id" class="form-select">
                <option value="">All employees</option>
                @foreach ($employees as $e)<option value="{{ $e->id }}" @selected(request('employee_id') == $e->id)>{{ $e->name }} ({{ $e->code }})</option>@endforeach
            </select>
        </div>
        <div class="col-8 col-sm-3">
            <label for="status" class="form-label">Show</label>
            <select id="status" name="status" class="form-select">
                @foreach (['outstanding' => 'Outstanding', 'repaid' => 'Repaid', 'cancelled' => 'Cancelled', 'all' => 'All'] as $k => $label)<option value="{{ $k }}" @selected($status === $k)>{{ $label }}</option>@endforeach
            </select>
        </div>
        <div class="col-4 col-sm-auto"><button class="btn btn-primary w-100">Show</button></div>
    </div>
</form>

<div class="erp-card">
    <div class="table-responsive">
        <table class="table align-middle mb-0 table-stack">
            <thead class="table-light"><tr><th>Date</th><th>Employee</th><th>Type</th><th class="text-end">Amount</th><th class="text-end">Instalment</th><th>From</th><th class="text-end">Recovered</th><th class="text-end">Outstanding</th><th></th></tr></thead>
            <tbody>
            @forelse ($rows as $r)
                <tr>
                    <td data-label="Date">{{ $r->loan->date->format('d M Y') }}</td>
                    <td data-label="Employee"><a href="{{ route('employees.show', $r->loan->employee) }}">{{ $r->loan->employee->name }}</a> <span class="text-secondary small">{{ $r->loan->employee->code }}</span></td>
                    <td data-label="Type">{{ \App\Models\EmployeeLoan::KINDS[$r->loan->kind] }}
                        @if ($r->loan->status === 'cancelled')<span class="badge text-bg-secondary">Cancelled</span>@elseif ($r->outstanding <= 0)<span class="badge text-bg-success">Repaid</span>@endif
                    </td>
                    <td data-label="Amount" class="amount">{{ $p($r->amount) }}</td>
                    <td data-label="Instalment" class="amount">{{ \App\Support\Money::format($r->loan->installment) }}</td>
                    <td data-label="From">{{ \Illuminate\Support\Carbon::createFromFormat('Y-m-d', $r->loan->recover_from.'-01')->format('M Y') }}</td>
                    <td data-label="Recovered" class="amount">{{ $p($r->recovered) }}</td>
                    <td data-label="Outstanding" class="amount fw-semibold">{{ $p($r->outstanding) }}</td>
                    <td data-label="" class="text-end"><a href="{{ route('employee-loans.show', $r->loan) }}" class="btn btn-sm btn-outline-secondary">Open</a></td>
                </tr>
            @empty
                <tr><td colspan="9" class="text-center text-secondary py-3">No loans or advances to show.</td></tr>
            @endforelse
            </tbody>
            @if ($rows->isNotEmpty())
                <tfoot>
                    <tr class="row-total"><td colspan="3">Total</td><td class="amount">{{ $p($totals['amount']) }}</td><td colspan="2"></td><td class="amount">{{ $p($totals['recovered']) }}</td><td class="amount">{{ $p($totals['outstanding']) }}</td><td></td></tr>
                </tfoot>
            @endif
        </table>
    </div>
</div>
@endsection
