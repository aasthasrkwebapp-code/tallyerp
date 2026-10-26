@extends('layouts.erp')

@section('title', 'Monthly Payroll')

@php($m = fn ($v) => \App\Support\Money::format($v))
@php($badge = ['draft' => 'warning', 'posted' => 'success', 'cancelled' => 'secondary'])

@section('content')
<div class="page-header">
    <div>
        <h1>Monthly payroll</h1>
        <p class="text-secondary mb-0">Prepare payslips for a month, check paid days, then post one salary journal.</p>
    </div>
</div>

@can('payroll.create')
    <form method="POST" action="{{ route('payroll-runs.store') }}" class="erp-card p-3 mb-3" style="max-width: 560px">
        @csrf
        <label for="month" class="form-label">Prepare payroll for</label>
        <div class="input-group">
            <input type="month" id="month" name="month" value="{{ old('month', $suggested) }}" class="form-control @error('month') is-invalid @enderror" required>
            <button class="btn btn-primary">Prepare</button>
        </div>
        @error('month')<div class="text-danger small mt-1">{{ $message }}</div>@enderror
    </form>
@endcan

<div class="erp-card">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0 table-stack">
            <thead class="table-light"><tr><th>Month</th><th class="text-end">Employees</th><th class="text-end">Gross</th><th class="text-end">Deductions</th><th class="text-end">Net pay</th><th class="text-end">Employer</th><th>Status</th></tr></thead>
            <tbody>
            @forelse ($runs as $run)
                <tr>
                    <td data-label="Month"><a href="{{ route('payroll-runs.show', $run) }}" class="fw-semibold">{{ $run->label() }}</a></td>
                    <td data-label="Employees" class="text-end">{{ $run->payslips_count }}</td>
                    <td data-label="Gross" class="amount">{{ $m($run->gross_total) }}</td>
                    <td data-label="Deductions" class="amount">{{ $m($run->deduction_total) }}</td>
                    <td data-label="Net pay" class="amount fw-semibold">{{ $m($run->net_total) }}</td>
                    <td data-label="Employer" class="amount">{{ $m($run->employer_total) }}</td>
                    <td data-label="Status"><span class="badge text-bg-{{ $badge[$run->status] }}">{{ ucfirst($run->status) }}</span>@if ($run->voucher) <span class="small text-secondary">{{ $run->voucher->voucher_number }}</span>@endif</td>
                </tr>
            @empty
                <tr><td colspan="7" class="text-secondary text-center py-4">No payroll yet. Add <a href="{{ route('employees.index') }}">employees</a> with a salary structure, then prepare a month.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
<div class="mt-3">{{ $runs->links() }}</div>
@endsection
