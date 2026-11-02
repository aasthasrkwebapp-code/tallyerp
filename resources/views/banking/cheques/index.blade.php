@extends('layouts.erp')

@section('title', 'Cheque Register')

@section('content')
@php($m = fn ($v) => \App\Support\Money::format($v))
<div class="page-header">
    <div>
        <h1>Cheque Register</h1>
        <p class="text-secondary mb-0">Cheques, drafts and transfers entered on bank vouchers. A cheque counts as cleared once its bank date is set in bank reconciliation.</p>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <a href="{{ route('cheque-books.index') }}" class="btn btn-outline-secondary"><i class="bi bi-journal-bookmark me-1"></i>Cheque books</a>
        @can('reports.export')
            <a class="btn btn-outline-secondary" href="{{ request()->fullUrlWithQuery(['export' => 'csv']) }}"><i class="bi bi-filetype-csv"></i> CSV</a>
            <a class="btn btn-outline-secondary" href="{{ request()->fullUrlWithQuery(['export' => 'xlsx']) }}"><i class="bi bi-file-earmark-excel"></i> Excel</a>
            <a class="btn btn-outline-secondary" href="{{ request()->fullUrlWithQuery(['export' => 'pdf']) }}"><i class="bi bi-file-earmark-pdf"></i> PDF</a>
        @endcan
    </div>
</div>

<form method="GET" class="erp-card p-3 mb-3">
    <div class="row g-2 align-items-end">
        <div class="col-12 col-sm-6 col-md-3 col-lg-2">
            <label class="form-label small" for="f-bank">Bank</label>
            <select id="f-bank" name="bank" class="form-select form-select-sm">
                <option value="">All banks</option>
                @foreach ($banks as $b)<option value="{{ $b->id }}" @selected((int) ($filters['bank'] ?? 0) === $b->id)>{{ $b->name }}</option>@endforeach
            </select>
        </div>
        <div class="col-6 col-md-2">
            <label class="form-label small" for="f-direction">Issued / received</label>
            <select id="f-direction" name="direction" class="form-select form-select-sm">
                <option value="">Both</option>
                <option value="issued" @selected(($filters['direction'] ?? '') === 'issued')>Issued</option>
                <option value="received" @selected(($filters['direction'] ?? '') === 'received')>Received</option>
            </select>
        </div>
        <div class="col-6 col-md-2">
            <label class="form-label small" for="f-status">Status</label>
            <select id="f-status" name="status" class="form-select form-select-sm">
                <option value="">All</option>
                @foreach (\App\Services\Banking\ChequeRegister::STATUSES as $k => $label)<option value="{{ $k }}" @selected(($filters['status'] ?? '') === $k)>{{ $label }}</option>@endforeach
            </select>
        </div>
        <div class="col-6 col-md-2 col-lg-1">
            <label class="form-label small" for="f-type">Instrument</label>
            <select id="f-type" name="type" class="form-select form-select-sm">
                <option value="">All</option>
                @foreach (\App\Models\Voucher::INSTRUMENTS as $k => $label)<option value="{{ $k }}" @selected(($filters['type'] ?? '') === $k)>{{ $label }}</option>@endforeach
            </select>
        </div>
        <div class="col-6 col-md-2 col-lg-1">
            <label class="form-label small" for="f-printed">Printed</label>
            <select id="f-printed" name="printed" class="form-select form-select-sm">
                <option value="">Any</option>
                <option value="no" @selected(($filters['printed'] ?? '') === 'no')>Not yet</option>
                <option value="yes" @selected(($filters['printed'] ?? '') === 'yes')>Printed</option>
            </select>
        </div>
        <div class="col-6 col-md-2 col-lg-1"><label class="form-label small" for="f-from">From</label><input id="f-from" type="date" name="from" value="{{ $filters['from'] ?? '' }}" class="form-control form-control-sm"></div>
        <div class="col-6 col-md-2 col-lg-1"><label class="form-label small" for="f-to">To</label><input id="f-to" type="date" name="to" value="{{ $filters['to'] ?? '' }}" class="form-control form-control-sm"></div>
        <div class="col-6 col-md-3 col-lg-2"><label class="form-label small" for="f-q">Number / name</label><input id="f-q" name="q" value="{{ $filters['q'] ?? '' }}" class="form-control form-control-sm" maxlength="50"></div>
        <div class="col-12 col-md-auto"><button class="btn btn-sm btn-primary">Show</button> <a href="{{ route('cheques.index') }}" class="btn btn-sm btn-link">Clear</a></div>
    </div>
</form>

<div class="d-flex flex-wrap gap-2 mb-3 small">
    @foreach ($totals as $status => $t)
        <a href="{{ request()->fullUrlWithQuery(['status' => $status]) }}" class="erp-card px-3 py-2 text-decoration-none text-body">
            @include('banking.cheques._badge', ['status' => $status]) <span class="ms-1">{{ $t['count'] }} · ₹{{ $m($t['amount']) }}</span>
        </a>
    @endforeach
</div>

<form method="GET" action="{{ route('cheques.print-batch') }}" target="_blank" id="batch-print">
@if ($printable)
    <div class="d-flex flex-wrap align-items-center gap-2 mb-2 no-print">
        <span class="form-check mb-0"><input type="checkbox" class="form-check-input" id="tick-all"><label class="form-check-label small" for="tick-all">Tick all</label></span>
        <button class="btn btn-sm btn-outline-primary" id="print-selected" disabled><i class="bi bi-printer me-1"></i>Print ticked cheques <span class="badge text-bg-primary" id="print-count">0</span></button>
        <span class="small text-secondary">Tick issued cheques of one bank to print them together, one leaf per page.</span>
    </div>
@endif
<div class="erp-card">
    <div class="table-responsive">
        <table class="table table-sm align-middle mb-0 table-stack">
            <thead class="table-light"><tr>@if ($printable)<th class="no-print" style="width: 2rem"><span class="visually-hidden">Print</span></th>@endif<th>Date</th><th>Number</th><th>Bank</th><th>Party / payee</th><th class="text-end">Amount</th><th>Status</th><th>Voucher</th></tr></thead>
            <tbody>
            @forelse ($rows as $r)
                <tr>
                    @if ($printable)<td data-label="Print" class="no-print">@if ($r->printable)<input type="checkbox" class="form-check-input tick" name="ids[]" value="{{ $r->voucher->id }}" aria-label="Print cheque {{ $r->number }}">@endif</td>@endif
                    <td data-label="Date">{{ \Illuminate\Support\Carbon::parse($r->date)->format('d-m-Y') }}</td>
                    <td data-label="Number"><span class="font-monospace">{{ $r->number ?? '—' }}</span>
                        <div class="small text-secondary">{{ \App\Models\Voucher::INSTRUMENTS[$r->type] ?? $r->type }} · {{ ucfirst($r->direction) }}</div></td>
                    <td data-label="Bank">{{ $r->bank?->name }}</td>
                    <td data-label="Party / payee">{{ $r->party ?? '—' }}</td>
                    <td data-label="Amount" class="amount">{{ $r->kind === 'spoilt' ? '—' : $m($r->amount) }}</td>
                    <td data-label="Status">@include('banking.cheques._badge', ['status' => $r->status])
                        @if ($r->bank_date)<div class="small text-secondary">on {{ $r->bank_date->format('d-m-Y') }}</div>@endif
                        @if ($r->days !== null)<div class="small text-secondary">{{ $r->days }} days</div>@endif
                        @if ($r->reason)<div class="small text-secondary">{{ \Illuminate\Support\Str::limit($r->reason, 60) }}</div>@endif
                        @if ($r->printable && $r->voucher->cheque_print_count)<div class="small text-secondary"><i class="bi bi-printer"></i> printed {{ $r->voucher->cheque_printed_at?->format('d-m-Y') }}@if ($r->voucher->cheque_print_count > 1) ({{ $r->voucher->cheque_print_count }}×)@endif</div>@endif
                    </td>
                    <td data-label="Voucher">
                        @if ($r->voucher)<a href="{{ route('vouchers.show', $r->voucher) }}">{{ $r->voucher->type?->name }} {{ $r->voucher->displayNumber() }}</a>@else — @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="{{ $printable ? 8 : 7 }}" class="text-center text-secondary py-4">No cheques match. Enter the cheque or transfer details under "Cheque / bank transfer details" on payment, receipt and contra vouchers.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
</form>
@endsection

@push('scripts')
<script>
(function () {
    var form = document.getElementById('batch-print');
    var button = document.getElementById('print-selected');
    if (!form || !button) return;
    var ticks = form.querySelectorAll('.tick');
    function update() {
        var n = form.querySelectorAll('.tick:checked').length;
        document.getElementById('print-count').textContent = n;
        button.disabled = n === 0;
    }
    form.addEventListener('change', function (e) {
        if (e.target.id === 'tick-all') ticks.forEach(function (t) { t.checked = e.target.checked; });
        update();
    });
    update();
})();
</script>
@endpush
