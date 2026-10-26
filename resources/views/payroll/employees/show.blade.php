@extends('layouts.erp')

@section('title', $employee->name)

@php($m = fn ($v) => \App\Support\Money::format($v))

@section('content')
<div class="page-header">
    <div>
        <h1>{{ $employee->name }} <small class="text-secondary fs-6">{{ $employee->code }}</small></h1>
        <p class="text-secondary mb-0">{{ $employee->designation ?: 'No designation' }}@if ($employee->department) · {{ $employee->department }}@endif · joined {{ $employee->date_of_joining->format('d M Y') }}@if ($employee->date_of_leaving) · left {{ $employee->date_of_leaving->format('d M Y') }}@endif</p>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <a href="{{ route('employees.index') }}" class="btn btn-outline-secondary">All employees</a>
        @can('payroll.edit')<a href="{{ route('employees.edit', $employee) }}" class="btn btn-primary">Edit</a>@endcan
        @can('payroll.delete')
            <form method="POST" action="{{ route('employees.destroy', $employee) }}" data-confirm="Delete employee {{ $employee->name }}?">
                @csrf @method('DELETE')
                <button class="btn btn-outline-danger">Delete</button>
            </form>
        @endcan
    </div>
</div>

<div class="row g-3">
    <div class="col-12 col-xl-5">
        <div class="erp-card mb-3">
            <div class="erp-card-header">Salary structure</div>
            <table class="table table-sm mb-0">
                <tbody>
                @forelse ($employee->payHeads->sortBy(fn ($p) => $p->payHead->sort_order) as $p)
                    <tr>
                        <td>{{ $p->payHead->name }} <span class="text-secondary small">{{ \App\Models\PayHead::TYPES[$p->payHead->type] }}</span></td>
                        <td class="text-end">{{ $p->payHead->calculation === 'flat' ? $m($p->amount) : (\App\Models\PayHead::CALCULATIONS[$p->payHead->calculation].($p->payHead->rate !== null ? ' · '.\App\Support\Decimal::trim($p->payHead->rate).'%' : '')) }}</td>
                    </tr>
                @empty
                    <tr><td class="text-secondary">No salary structure yet.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <div class="erp-card">
            <div class="erp-card-header">Details</div>
            <dl class="row mb-0 p-3 small">
                <dt class="col-5">PAN</dt><dd class="col-7">{{ $employee->pan ?: '—' }}</dd>
                <dt class="col-5">PF UAN</dt><dd class="col-7">{{ $employee->uan ?: '—' }} @unless ($employee->pf_applicable)<span class="text-secondary">(PF not applicable)</span>@endunless</dd>
                <dt class="col-5">ESI number</dt><dd class="col-7">{{ $employee->esi_number ?: '—' }} @unless ($employee->esi_applicable)<span class="text-secondary">(ESI not applicable)</span>@endunless</dd>
                <dt class="col-5">Bank</dt><dd class="col-7">{{ $employee->bank_name ?: '—' }} {{ $employee->bank_account }} {{ $employee->ifsc }}</dd>
                <dt class="col-5">Contact</dt><dd class="col-7">{{ $employee->phone ?: '—' }} {{ $employee->email }}</dd>
                @if ($employee->address)<dt class="col-5">Address</dt><dd class="col-7">{{ $employee->address }}</dd>@endif
            </dl>
        </div>
    </div>
    <div class="col-12 col-xl-7">
        <div class="erp-card">
            <div class="erp-card-header">Payslips</div>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0 table-stack">
                    <thead class="table-light"><tr><th>Month</th><th class="text-end">Paid days</th><th class="text-end">Gross</th><th class="text-end">Deductions</th><th class="text-end">Net pay</th><th></th></tr></thead>
                    <tbody>
                    @forelse ($payslips as $s)
                        <tr>
                            <td data-label="Month">{{ $s->run->label() }} @if ($s->run->isDraft())<span class="badge text-bg-warning">Draft</span>@endif</td>
                            <td data-label="Paid days" class="text-end">{{ \App\Support\Decimal::trim($s->paid_days) }} / {{ $s->days_in_month }}</td>
                            <td data-label="Gross" class="amount">{{ $m($s->gross) }}</td>
                            <td data-label="Deductions" class="amount">{{ $m($s->deductions) }}</td>
                            <td data-label="Net pay" class="amount fw-semibold">{{ $m($s->net) }}</td>
                            <td data-label="" class="text-end"><a href="{{ route('payroll-runs.payslip', [$s->run, $s]) }}" class="btn btn-sm btn-outline-secondary">Payslip</a></td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="text-secondary text-center py-3">No payslips yet.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
@endsection
