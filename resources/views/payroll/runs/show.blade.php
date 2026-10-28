@extends('layouts.erp')

@section('title', 'Payroll '.$run->label())

@php($m = fn ($v) => \App\Support\Money::format($v))
@php($p = fn ($paise) => \App\Support\Money::format($paise, paiseInput: true))
@php($badge = ['draft' => 'warning', 'posted' => 'success', 'cancelled' => 'secondary'])
@php($editable = $run->isDraft() && auth()->user()->can('payroll.edit'))

@section('content')
<div class="page-header">
    <div>
        <h1>Payroll {{ $run->label() }} <span class="badge text-bg-{{ $badge[$run->status] }} align-middle">{{ ucfirst($run->status) }}</span></h1>
        <p class="text-secondary mb-0">{{ $slips->count() }} {{ \Illuminate\Support\Str::plural('employee', $slips->count()) }} @if ($run->voucher) · journal <a href="{{ route('vouchers.show', $run->voucher) }}">{{ $run->voucher->voucher_number }}</a> dated {{ $run->date->format('d M Y') }}@endif</p>
    </div>
    <div class="d-flex flex-wrap gap-2 no-print">
        <a href="{{ route('payroll-runs.index') }}" class="btn btn-outline-secondary">All months</a>
        <a href="{{ request()->fullUrlWithQuery(['export' => 'csv']) }}" class="btn btn-outline-secondary"><i class="bi bi-filetype-csv"></i> CSV</a>
        <a href="{{ request()->fullUrlWithQuery(['export' => 'xlsx']) }}" class="btn btn-outline-secondary"><i class="bi bi-file-earmark-excel"></i> Excel</a>
        <a href="{{ request()->fullUrlWithQuery(['export' => 'pdf']) }}" class="btn btn-outline-secondary"><i class="bi bi-file-earmark-pdf"></i> PDF</a>
        <button type="button" class="btn btn-outline-secondary" onclick="window.print()"><i class="bi bi-printer"></i> Print</button>
    </div>
</div>

@if ($run->status === 'cancelled')
    <div class="alert alert-secondary">Cancelled: {{ $run->cancellation_reason }}. Its journal is cancelled too; you can prepare this month again.</div>
@endif

<div class="row g-3 mb-3">
    @foreach (['Gross pay' => $run->gross_total, 'Deductions' => $run->deduction_total, 'Net pay' => $run->net_total, 'Employer contributions' => $run->employer_total] as $label => $value)
        <div class="col-6 col-lg-3"><div class="erp-card p-3 h-100"><div class="small text-secondary">{{ $label }}</div><div class="fs-5 fw-semibold">{{ $m($value) }}</div></div></div>
    @endforeach
</div>

<form method="POST" action="{{ route('payroll-runs.update', $run) }}" id="paysheet">
    @csrf @method('PUT')
    <div class="erp-card mb-3">
        <div class="erp-card-header">Pay sheet</div>
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0 table-stack table-report">
                <thead class="table-light">
                    <tr>
                        <th>Employee</th><th class="text-end">Paid days</th>
                        @foreach ($heads as $h)<th class="text-end text-nowrap {{ $h->type === 'earning' ? '' : ($h->type === 'deduction' ? 'text-danger' : 'text-secondary') }}">{{ $h->name }}</th>@endforeach
                        <th class="text-end">Gross</th><th class="text-end">Net pay</th><th class="no-print"></th>
                    </tr>
                </thead>
                <tbody>
                @foreach ($slips as $s)
                    <tr>
                        <td data-label="Employee"><a href="{{ route('employees.show', $s->employee_id) }}">{{ $s->employee_name }}</a> <span class="text-secondary small">{{ $s->employee_code }}</span></td>
                        <td data-label="Paid days" class="text-end">
                            @if ($editable)
                                <input name="paid_days[{{ $s->employee_id }}]" value="{{ old("paid_days.{$s->employee_id}", \App\Support\Decimal::trim($s->paid_days)) }}" class="form-control form-control-sm text-end d-inline-block @error("paid_days.{$s->employee_id}") is-invalid @enderror" style="width: 5rem" inputmode="decimal" aria-label="Paid days for {{ $s->employee_name }}">
                                <span class="small text-secondary">/ {{ $s->days_in_month }}</span>
                            @else
                                {{ \App\Support\Decimal::trim($s->paid_days) }} / {{ $s->days_in_month }}
                            @endif
                            @if ($s->from_attendance)<i class="bi bi-calendar-check text-success ms-1" title="From the attendance register"></i>@endif
                            @if ((float) $s->overtime_hours > 0)<div class="small text-secondary">OT {{ \App\Support\Decimal::trim($s->overtime_hours) }} h</div>@endif
                        </td>
                        @foreach ($heads as $h)
                            <td data-label="{{ $h->name }}" class="amount">{{ $m($amounts[$s->id]->get($h->id)?->amount ?? 0) }}</td>
                        @endforeach
                        <td data-label="Gross" class="amount">{{ $m($s->gross) }}</td>
                        <td data-label="Net pay" class="amount fw-semibold">{{ $m($s->net) }}</td>
                        <td data-label="" class="text-end no-print"><a href="{{ route('payroll-runs.payslip', [$run, $s]) }}" class="btn btn-sm btn-outline-secondary">Payslip</a></td>
                    </tr>
                @endforeach
                </tbody>
                <tfoot>
                    <tr class="row-total">
                        <td>Total</td><td></td>
                        @foreach ($heads as $h)<td class="amount">{{ $p($headTotals[$h->id]) }}</td>@endforeach
                        <td class="amount">{{ $m($run->gross_total) }}</td><td class="amount">{{ $m($run->net_total) }}</td><td class="no-print"></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
    @if ($editable)
        <button class="btn btn-outline-primary mb-3">Recalculate</button>
        <button type="submit" form="from-attendance" class="btn btn-outline-secondary mb-3">Take paid days from attendance</button>
        <div class="small text-secondary mb-3">Recalculating also applies changes made to salary structures, pay heads, overtime in the <a href="{{ route('attendance.index', ['month' => $run->month]) }}">attendance register</a> and loan instalments. <i class="bi bi-calendar-check text-success"></i> marks paid days taken from attendance.</div>
    @endif
</form>
@if ($editable)
    <form method="POST" action="{{ route('payroll-runs.attendance', $run) }}" id="from-attendance" class="d-none">@csrf</form>
@endif

<div class="d-flex flex-wrap gap-3 no-print">
    @if ($run->isDraft())
        @can('payroll.edit')
            <form method="POST" action="{{ route('payroll-runs.post', $run) }}" class="erp-card p-3" data-confirm="Post payroll for {{ $run->label() }}?">
                @csrf
                <label for="date" class="form-label">Post salary journal dated</label>
                <div class="input-group">
                    <input type="date" id="date" name="date" value="{{ old('date', $run->date->toDateString()) }}" class="form-control @error('date') is-invalid @enderror">
                    <button class="btn btn-primary">Post payroll</button>
                </div>
                @error('date')<div class="text-danger small mt-1">{{ $message }}</div>@enderror
            </form>
        @endcan
        @can('payroll.delete')
            <form method="POST" action="{{ route('payroll-runs.destroy', $run) }}" class="align-self-end" data-confirm="Delete this draft payroll?">
                @csrf @method('DELETE')
                <button class="btn btn-outline-danger">Delete draft</button>
            </form>
        @endcan
    @elseif ($run->status === 'posted')
        <div class="erp-card p-3 small" style="max-width: 520px">
            Pay salaries with a <a href="{{ route('vouchers.create', ['type' => \App\Models\VoucherType::forBase('payment')?->id]) }}">payment voucher</a>: debit <strong>Salary Payable</strong>, credit your bank. Deposit PF, ESI, professional tax and TDS the same way from their payable ledgers.
        </div>
        @can('payroll.delete')
            <form method="POST" action="{{ route('payroll-runs.cancel', $run) }}" class="erp-card p-3" data-confirm="Cancel payroll for {{ $run->label() }}?">
                @csrf
                <label for="reason" class="form-label">Cancel this payroll</label>
                <div class="input-group">
                    <input id="reason" name="reason" class="form-control" placeholder="Reason" required maxlength="255">
                    <button class="btn btn-outline-danger">Cancel payroll</button>
                </div>
            </form>
        @endcan
    @endif
</div>
@endsection
