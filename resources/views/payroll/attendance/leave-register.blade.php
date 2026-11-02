@extends('layouts.erp')

@section('title', 'Leave Register')

@php($fmt = fn ($v) => $v === null ? '—' : \App\Support\Decimal::trim(number_format($v, 1, '.', '')))

@section('content')
<div class="page-header">
    <div>
        <h1>Leave register</h1>
        <p class="text-secondary mb-0">Leave taken and balance for {{ \Illuminate\Support\Carbon::parse($from)->format('d M Y') }} – {{ \Illuminate\Support\Carbon::parse($to)->format('d M Y') }}, from the attendance register.</p>
    </div>
    <div class="d-flex flex-wrap gap-2 no-print">
        <a href="{{ route('attendance.index') }}" class="btn btn-outline-secondary">Attendance</a>
        <a href="{{ route('leave-types.index') }}" class="btn btn-outline-secondary">Leave types</a>
        <a href="{{ route('leave-openings') }}" class="btn btn-outline-secondary">Opening balances</a>
        <a href="{{ route('leave-encashments.index') }}" class="btn btn-outline-secondary">Encashment</a>
        <a href="{{ request()->fullUrlWithQuery(['export' => 'csv']) }}" class="btn btn-outline-secondary"><i class="bi bi-filetype-csv"></i> CSV</a>
        <a href="{{ request()->fullUrlWithQuery(['export' => 'xlsx']) }}" class="btn btn-outline-secondary"><i class="bi bi-file-earmark-excel"></i> Excel</a>
        <a href="{{ request()->fullUrlWithQuery(['export' => 'pdf']) }}" class="btn btn-outline-secondary"><i class="bi bi-file-earmark-pdf"></i> PDF</a>
    </div>
</div>

<form method="GET" class="erp-card p-3 mb-3 no-print">
    <div class="row g-2 align-items-end">
        <div class="col-8 col-sm-auto">
            <label for="date" class="form-label">Financial year containing</label>
            <input type="date" id="date" name="date" value="{{ $date }}" class="form-control" required>
        </div>
        <div class="col-4 col-sm-auto"><button class="btn btn-primary w-100">Show</button></div>
    </div>
</form>

<div class="erp-card">
    <div class="table-responsive">
        <table class="table table-sm align-middle mb-0 table-sticky-first table-report">
            <thead class="table-light">
                <tr>
                    <th rowspan="2">Employee</th>
                    @foreach ($types as $t)<th colspan="{{ 3 + ($carried[$t->id] ? 1 : 0) + ($encashed ? 1 : 0) }}" class="text-center">{{ $t->name }} ({{ $t->code }}){{ $t->is_paid ? '' : ' · unpaid' }}</th>@endforeach
                </tr>
                <tr>@foreach ($types as $t)@if ($carried[$t->id])<th class="text-end">Carried in</th>@endif<th class="text-end">Allowed</th><th class="text-end">Taken</th>@if ($encashed)<th class="text-end">Encashed</th>@endif<th class="text-end">Balance</th>@endforeach</tr>
            </thead>
            <tbody>
            @forelse ($rows as $r)
                <tr>
                    <td><a href="{{ route('employees.show', $r->employee) }}">{{ $r->employee->name }}</a> <span class="text-secondary small">{{ $r->employee->code }}</span></td>
                    @foreach ($types as $t)
                        @php($c = $r->types[$t->id])
                        @if ($carried[$t->id])<td class="text-end text-secondary">{{ $fmt($c['carried']) }}</td>@endif
                        <td class="text-end">{{ $fmt($c['allowed']) }}</td>
                        <td class="text-end">{{ $fmt($c['taken']) }}</td>
                        @if ($encashed)<td class="text-end">{{ $fmt($c['encashed']) }}</td>@endif
                        <td class="text-end {{ $c['balance'] !== null && $c['balance'] < 0 ? 'text-danger fw-semibold' : '' }}">{{ $fmt($c['balance']) }}</td>
                    @endforeach
                </tr>
            @empty
                <tr><td colspan="{{ 1 + (3 + ($encashed ? 1 : 0)) * $types->count() + count(array_filter($carried)) }}" class="text-center text-secondary py-3">No employees in this year.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
<p class="small text-secondary mt-2">A negative balance (in red) means more leave was taken than allowed. The allowance is per financial year. Opening balances entered for the books' first year are carried in. For leave types that carry forward, the unused balance at each year end (up to the type's limit) is added to the next year's allowance; an overdrawn year carries nothing. Encashed leave comes off the balance.</p>
@endsection
