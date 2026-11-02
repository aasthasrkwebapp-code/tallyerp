@extends('layouts.erp')

@section('title', \App\Models\EmployeeLoan::KINDS[$loan->kind].' to '.$loan->employee->name)

@php($m = fn ($v) => \App\Support\Money::format($v))
@php($p = fn ($paise) => \App\Support\Money::format($paise, paiseInput: true))

@section('content')
<div class="page-header">
    <div>
        <h1>{{ \App\Models\EmployeeLoan::KINDS[$loan->kind] }} to {{ $loan->employee->name }}
            @if ($loan->status === 'cancelled')<span class="badge text-bg-secondary align-middle">Cancelled</span>@elseif ($summary->outstanding <= 0)<span class="badge text-bg-success align-middle">Repaid</span>@endif
        </h1>
        <p class="text-secondary mb-0">Paid {{ $loan->date->format('d M Y') }} from {{ $loan->paidAccount->name }}
            @if ($loan->voucher) · voucher <a href="{{ route('vouchers.show', $loan->voucher) }}">{{ $loan->voucher->voucher_number }}</a>@endif
            @if ($loan->description) · {{ $loan->description }}@endif</p>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <a href="{{ route('employee-loans.index') }}" class="btn btn-outline-secondary">All loans</a>
        <a href="{{ route('employees.show', $loan->employee) }}" class="btn btn-outline-secondary">Employee</a>
    </div>
</div>

@if ($loan->status === 'cancelled')
    <div class="alert alert-secondary">Cancelled: {{ $loan->cancellation_reason }}. Its payment voucher is cancelled too.</div>
@endif

<div class="row g-3 mb-3">
    @foreach (['Amount' => $summary->amount, 'Recovered' => $summary->recovered, 'Outstanding' => $summary->outstanding] as $label => $value)
        <div class="col-6 col-lg-3"><div class="erp-card p-3 h-100"><div class="small text-secondary">{{ $label }}</div><div class="fs-5 fw-semibold">{{ $p($value) }}</div></div></div>
    @endforeach
    <div class="col-6 col-lg-3"><div class="erp-card p-3 h-100"><div class="small text-secondary">Monthly instalment</div><div class="fs-5 fw-semibold">{{ $m($loan->installment) }}</div><div class="small text-secondary">from {{ \Illuminate\Support\Carbon::createFromFormat('Y-m-d', $loan->recover_from.'-01')->format('M Y') }} · {{ $loan->account->name }}</div></div></div>
</div>

<div class="erp-card mb-3">
    <div class="erp-card-header">Recoveries</div>
    <div class="table-responsive">
        <table class="table table-sm align-middle mb-0 table-stack">
            <thead class="table-light"><tr><th>Payroll</th><th>Status</th><th class="text-end">Recovered</th><th class="text-end">Balance</th></tr></thead>
            <tbody>
            @forelse ($schedule as $s)
                <tr class="{{ $s->counts ? '' : 'text-secondary' }}">
                    <td data-label="Payroll"><a href="{{ route('payroll-runs.show', $s->run) }}">{{ $s->run->label() }}</a></td>
                    <td data-label="Status">{{ ucfirst($s->run->status) }}@unless ($s->counts) <span class="small">(not counted)</span>@endunless</td>
                    <td data-label="Recovered" class="amount">{{ $m($s->recovery->amount) }}</td>
                    <td data-label="Balance" class="amount">{{ $p($s->balance) }}</td>
                </tr>
            @empty
                <tr><td colspan="4" class="text-center text-secondary py-3">Nothing recovered yet. Payroll from {{ \Illuminate\Support\Carbon::createFromFormat('Y-m-d', $loan->recover_from.'-01')->format('F Y') }} deducts the instalment.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>

@if ($loan->status === 'active')
    @can('payroll.delete')
        <form method="POST" action="{{ route('employee-loans.cancel', $loan) }}" class="erp-card p-3 no-print" style="max-width: 520px" data-confirm="Cancel this {{ strtolower(\App\Models\EmployeeLoan::KINDS[$loan->kind]) }} and its payment voucher?">
            @csrf
            <label for="reason" class="form-label">Cancel (entered by mistake)</label>
            <div class="input-group">
                <input id="reason" name="reason" class="form-control @error('loan') is-invalid @enderror" placeholder="Reason" required maxlength="255">
                <button class="btn btn-outline-danger">Cancel</button>
            </div>
            @error('loan')<div class="text-danger small mt-1">{{ $message }}</div>@enderror
            <div class="small text-secondary mt-1">Only possible before any payroll recovers it.</div>
        </form>
    @endcan
@endif
@endsection
