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

<div class="erp-card">
    <div class="table-responsive">
        <table class="table table-sm align-middle mb-0 table-stack">
            <thead class="table-light"><tr><th>Date</th><th>Number</th><th>Bank</th><th>Party / payee</th><th class="text-end">Amount</th><th>Status</th><th>Voucher</th></tr></thead>
            <tbody>
            @forelse ($rows as $r)
                <tr>
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
                    </td>
                    <td data-label="Voucher">
                        @if ($r->voucher)<a href="{{ route('vouchers.show', $r->voucher) }}">{{ $r->voucher->type?->name }} {{ $r->voucher->displayNumber() }}</a>@else — @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="7" class="text-center text-secondary py-4">No cheques match. Enter the cheque or transfer details under "Cheque / bank transfer details" on payment, receipt and contra vouchers.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
