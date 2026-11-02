@extends('layouts.erp')

@section('title', 'Leave Encashment')

@section('content')
<div class="page-header">
    <div>
        <h1>Leave encashment</h1>
        <p class="text-secondary mb-0">Unused leave paid out to employees. Each is a payment voucher and comes off the year's leave balance.</p>
    </div>
    <div class="d-flex flex-wrap gap-2 no-print">
        @can('payroll.create')<a href="{{ route('leave-encashments.create') }}" class="btn btn-primary"><i class="bi bi-plus-lg me-1"></i>Encash leave</a>@endcan
        <a href="{{ route('leave-register') }}" class="btn btn-outline-secondary">Leave register</a>
        <a href="{{ request()->fullUrlWithQuery(['export' => 'csv']) }}" class="btn btn-outline-secondary"><i class="bi bi-filetype-csv"></i> CSV</a>
    </div>
</div>

<ul class="nav nav-pills mb-3">
    @foreach (['active' => 'Paid', 'cancelled' => 'Cancelled', 'all' => 'All'] as $k => $label)
        <li class="nav-item"><a href="{{ route('leave-encashments.index', ['status' => $k]) }}" class="nav-link {{ $status === $k ? 'active' : '' }}">{{ $label }}</a></li>
    @endforeach
</ul>

<div class="erp-card">
    <div class="table-responsive">
        <table class="table table-sm align-middle mb-0 table-stack">
            <thead class="table-light"><tr><th>Date</th><th>Employee</th><th>Leave</th><th class="text-end">Days</th><th class="text-end">Rate</th><th class="text-end">Amount</th><th>Voucher</th><th class="text-end no-print"></th></tr></thead>
            <tbody>
            @forelse ($rows as $e)
                <tr>
                    <td data-label="Date">{{ $e->date->format('d-m-Y') }}</td>
                    <td data-label="Employee"><a href="{{ route('employees.show', $e->employee) }}">{{ $e->employee->name }}</a> <span class="small text-secondary">{{ $e->employee->code }}</span></td>
                    <td data-label="Leave">{{ $e->leaveType->name }}</td>
                    <td data-label="Days" class="text-end">{{ \App\Support\Decimal::trim($e->days) }}</td>
                    <td data-label="Rate" class="amount">{{ \App\Support\Money::format($e->rate) }}</td>
                    <td data-label="Amount" class="amount">{{ \App\Support\Money::format($e->amount) }}</td>
                    <td data-label="Voucher">@if ($e->voucher)<a href="{{ route('vouchers.show', $e->voucher) }}">{{ $e->voucher->voucher_number }}</a>@endif @if ($e->status === 'cancelled')<span class="badge text-bg-secondary">Cancelled</span>@endif</td>
                    <td data-label="" class="text-end no-print">
                        @if ($e->status === 'active')
                            @can('payroll.delete')
                                <form method="POST" action="{{ route('leave-encashments.cancel', $e) }}" class="d-inline-flex gap-1" data-confirm="Cancel this encashment and its voucher?">
                                    @csrf
                                    <input name="reason" class="form-control form-control-sm" placeholder="Reason" required maxlength="255" aria-label="Reason" style="max-width: 9rem">
                                    <button class="btn btn-sm btn-outline-danger">Cancel</button>
                                </form>
                            @endcan
                        @else
                            <span class="small text-secondary">{{ $e->cancellation_reason }}</span>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="8" class="text-center text-secondary py-4">No leave encashments.</td></tr>
            @endforelse
            </tbody>
            @if ($total)
                <tfoot><tr class="row-total"><td colspan="5">Total paid</td><td class="amount">{{ \App\Support\Money::format($total, paiseInput: true) }}</td><td colspan="2"></td></tr></tfoot>
            @endif
        </table>
    </div>
</div>
@endsection
