@extends('layouts.erp')

@section('title', 'Attendance '.$start->format('F Y'))

@php($editable = auth()->user()->can('payroll.edit'))
@php($basic = ['absent' => 'A', 'half_day' => '½', 'week_off' => 'W', 'holiday' => 'H'])

@section('content')
<div class="page-header">
    <div>
        <h1>Attendance register</h1>
        <p class="text-secondary mb-0">{{ $start->format('F Y') }} · a day left blank counts as present.</p>
    </div>
    <div class="d-flex flex-wrap gap-2 no-print">
        <a href="{{ route('leave-register') }}" class="btn btn-outline-secondary">Leave register</a>
        <a href="{{ route('leave-types.index') }}" class="btn btn-outline-secondary">Leave types</a>
        <a href="{{ request()->fullUrlWithQuery(['month' => $month, 'export' => 'csv']) }}" class="btn btn-outline-secondary"><i class="bi bi-filetype-csv"></i> CSV</a>
        <a href="{{ request()->fullUrlWithQuery(['month' => $month, 'export' => 'xlsx']) }}" class="btn btn-outline-secondary"><i class="bi bi-file-earmark-excel"></i> Excel</a>
        <a href="{{ request()->fullUrlWithQuery(['month' => $month, 'export' => 'pdf']) }}" class="btn btn-outline-secondary"><i class="bi bi-file-earmark-pdf"></i> PDF</a>
    </div>
</div>

<form method="GET" class="erp-card p-3 mb-3 no-print">
    <div class="row g-2 align-items-end">
        <div class="col-8 col-sm-auto">
            <label for="month" class="form-label">Month</label>
            <input type="month" id="month" name="month" value="{{ $month }}" class="form-control" required>
        </div>
        <div class="col-4 col-sm-auto"><button class="btn btn-primary w-100">Show</button></div>
    </div>
</form>

<div class="small text-secondary mb-2">
    <strong>Codes:</strong> A absent · ½ half day (half absent) · W weekly off · H holiday
    @foreach ($leaveTypes as $t) · {{ $t->code }} {{ $t->name }}{{ $t->is_paid ? '' : ' (unpaid)' }}@endforeach
    · ½ before a leave code is a half day on leave.
</div>

@if ($register->isEmpty())
    <div class="erp-card p-4 text-center text-secondary">No active employees were on the rolls in {{ $start->format('F Y') }}. <a href="{{ route('employees.index') }}">Add employees</a>.</div>
@else
<form method="POST" action="{{ route('attendance.update') }}" id="attendance-form">
    @csrf @method('PUT')
    <input type="hidden" name="month" value="{{ $month }}">
    @if ($editable)
        <div class="d-flex flex-wrap gap-2 mb-2 no-print">
            <button type="button" class="btn btn-sm btn-outline-secondary" data-fill="week_off" data-weekday="0">Mark Sundays as weekly off</button>
            <button type="button" class="btn btn-sm btn-outline-secondary" data-fill="">Clear the month</button>
        </div>
    @endif
    <div class="erp-card mb-3">
        <div class="table-responsive">
            <table class="table table-sm table-bordered align-middle mb-0 table-sticky-first attendance-grid">
                <thead class="table-light">
                    <tr>
                        <th>Employee</th>
                        @for ($d = 1; $d <= $end->day; $d++)
                            @php($date = $start->copy()->day($d))
                            <th class="text-center px-0 {{ $date->isSunday() ? 'text-danger' : '' }}" title="{{ $date->format('l d M') }}">{{ $d }}<br><span class="fw-normal small">{{ substr($date->format('D'), 0, 2) }}</span></th>
                        @endfor
                        <th class="text-end">OT hours</th>
                        <th class="text-end">Paid days</th>
                    </tr>
                </thead>
                <tbody>
                @foreach ($register as $r)
                    @php($e = $r->employee)
                    <tr data-employee="{{ $e->id }}">
                        <td><a href="{{ route('employees.show', $e) }}">{{ $e->name }}</a> <span class="text-secondary small">{{ $e->code }}</span></td>
                        @for ($d = 1; $d <= $end->day; $d++)
                            @if ($d < $r->on_rolls[0] || $d > $r->on_rolls[1])
                                <td class="text-center text-secondary att-off" title="Not on the rolls">–</td>
                            @else
                                @php([$status, $lt] = $r->days[$d] ?? ['present', null])
                                @php($value = old("cells.{$e->id}.{$d}", $status === 'present' ? '' : ($lt ? "$status:$lt" : $status)))
                                <td class="p-0 text-center" data-weekday="{{ $start->copy()->day($d)->dayOfWeek }}">
                                    <select name="cells[{{ $e->id }}][{{ $d }}]" class="att @error("cells.{$e->id}.{$d}") is-invalid @enderror" data-v="{{ $value }}" aria-label="{{ $e->name }}, {{ $start->copy()->day($d)->format('d M') }}" @disabled(! $editable)>
                                        <option value=""></option>
                                        @foreach ($basic as $k => $code)<option value="{{ $k }}" @selected($value === $k)>{{ $code }}</option>@endforeach
                                        @foreach ($leaveTypes as $t)
                                            <option value="leave:{{ $t->id }}" @selected($value === "leave:{$t->id}")>{{ $t->code }}</option>
                                            <option value="half_leave:{{ $t->id }}" @selected($value === "half_leave:{$t->id}")>½{{ $t->code }}</option>
                                        @endforeach
                                    </select>
                                </td>
                            @endif
                        @endfor
                        <td class="text-end">
                            <input name="overtime[{{ $e->id }}]" value="{{ old("overtime.{$e->id}", $r->overtime === '0' ? '' : $r->overtime) }}" class="form-control form-control-sm text-end ot @error("overtime.{$e->id}") is-invalid @enderror" inputmode="decimal" aria-label="Overtime hours for {{ $e->name }}" @disabled(! $editable)>
                        </td>
                        <td class="text-end fw-semibold" title="Present {{ $r->summary['present'] }} · absent {{ $r->summary['absent'] }} · paid leave {{ $r->summary['leave'] }} · without pay {{ $r->summary['unpaid_leave'] }} · off {{ $r->summary['off'] }}">{{ $r->paid_days }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </div>
    @if ($editable)
        <button class="btn btn-primary">Save attendance</button>
        <span class="small text-secondary ms-2">Then prepare or recalculate the month's payroll to use the paid days and overtime.</span>
    @endif
</form>
@endif
@endsection

@push('scripts')
<script>
(() => {
    const form = document.getElementById('attendance-form');
    if (!form) return;
    const paint = s => { s.dataset.v = s.value; };
    form.querySelectorAll('select.att').forEach(s => s.addEventListener('change', () => paint(s)));
    form.querySelectorAll('[data-fill]').forEach(btn => btn.addEventListener('click', () => {
        const wd = btn.dataset.weekday;
        form.querySelectorAll('td[data-weekday]').forEach(td => {
            if (wd !== undefined && td.dataset.weekday !== wd) return;
            const s = td.querySelector('select.att');
            if (s && (btn.dataset.fill === '' || s.value === '')) { s.value = btn.dataset.fill; paint(s); }
        });
    }));
    // Send one field per employee instead of one per day, so large registers stay within the server's input limit.
    form.addEventListener('submit', () => {
        form.querySelectorAll('tr[data-employee]').forEach(tr => {
            const codes = {};
            tr.querySelectorAll('select.att').forEach(s => {
                const day = s.name.match(/\[(\d+)\]$/)[1];
                codes[day] = s.value;
                s.removeAttribute('name');
            });
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'days[' + tr.dataset.employee + ']';
            input.value = JSON.stringify(codes);
            form.appendChild(input);
        });
    });
})();
</script>
@endpush
