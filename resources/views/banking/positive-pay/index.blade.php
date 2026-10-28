@extends('layouts.erp')

@section('title', 'Positive Pay')

@section('content')
@php($m = fn ($v) => \App\Support\Money::format($v))
<div class="page-header">
    <div>
        <h1>Positive pay</h1>
        <p class="text-secondary mb-0">Report issued cheques to the bank (CTS Positive Pay System) so it checks them before paying. Cheques already reported and then cancelled go out as cancellations.</p>
    </div>
    <div class="d-flex flex-wrap gap-2 no-print">
        <a href="{{ route('cheques.index', ['direction' => 'issued', 'type' => 'cheque']) }}" class="btn btn-outline-secondary">Cheque register</a>
    </div>
</div>

@if ($banks->isEmpty())
    <div class="alert alert-info">There are no bank ledgers.</div>
@else
<form method="GET" class="erp-card p-3 mb-3 no-print">
    <div class="row g-2 align-items-end">
        <div class="col-12 col-md-5">
            <label for="bank" class="form-label">Bank</label>
            <select id="bank" name="bank" class="form-select">
                @foreach ($banks as $b)<option value="{{ $b->id }}" @selected($bank?->id === $b->id)>{{ $b->name }}</option>@endforeach
            </select>
        </div>
        <div class="col-8 col-md-4">
            <label for="threshold" class="form-label">Cheques of at least (₹)</label>
            <input id="threshold" name="threshold" inputmode="decimal" class="form-control text-end" value="{{ \App\Support\Decimal::trim($threshold) }}">
        </div>
        <div class="col-4 col-md-3"><button class="btn btn-outline-primary w-100">Show</button></div>
    </div>
</form>

@if ($bank && ! $bank->bank_account_number)
    <div class="alert alert-warning">{{ $bank->name }} has no account number. Add it on <a href="{{ route('ledgers.edit', $bank) }}">the ledger</a>; the bank matches cheques by it.</div>
@endif
@error('bank')<div class="alert alert-danger">{{ $message }}</div>@enderror

<div class="erp-card mb-3">
    <div class="erp-card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
        <span>Due to report: {{ $due['issue']->count() }} cheque(s), {{ $due['cancel']->count() }} cancellation(s)</span>
        @can('banking.create')
            @if ($due['issue']->isNotEmpty() || $due['cancel']->isNotEmpty())
                <form method="POST" action="{{ route('positive-pay.store') }}" class="no-print">
                    @csrf
                    <input type="hidden" name="bank" value="{{ $bank->id }}"><input type="hidden" name="threshold" value="{{ $threshold }}">
                    <button class="btn btn-sm btn-primary"><i class="bi bi-file-earmark-arrow-down me-1"></i>Make positive pay file</button>
                </form>
            @endif
        @endcan
    </div>
    <div class="table-responsive">
        <table class="table table-sm align-middle mb-0 table-stack">
            <thead class="table-light"><tr><th>Action</th><th>Cheque</th><th>Date</th><th>Payee</th><th class="text-end">Amount</th><th>Voucher</th><th>Status</th></tr></thead>
            <tbody>
            @forelse ($due['issue']->map(fn ($r) => [$r, 'issue'])->concat($due['cancel']->map(fn ($r) => [$r, 'cancel'])) as [$r, $action])
                <tr>
                    <td data-label="Action">{!! $action === 'issue' ? '<span class="badge text-bg-primary">Issue</span>' : '<span class="badge text-bg-danger">Cancel</span>' !!}</td>
                    <td data-label="Cheque">{{ $r->number }}</td>
                    <td data-label="Date">{{ \Illuminate\Support\Carbon::parse($r->date)->format('d-m-Y') }}</td>
                    <td data-label="Payee">{{ $r->party }}</td>
                    <td data-label="Amount" class="amount">{{ $m($r->amount) }}</td>
                    <td data-label="Voucher"><a href="{{ route('vouchers.show', $r->voucher) }}">{{ $r->voucher->displayNumber() }}</a></td>
                    <td data-label="Status">{{ \App\Services\Banking\ChequeRegister::STATUSES[$r->status] }}</td>
                </tr>
            @empty
                <tr><td colspan="7" class="text-center text-secondary py-4">Nothing to report for this bank.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>

<div class="erp-card">
    <div class="erp-card-header">Files made</div>
    <div class="table-responsive">
        <table class="table table-sm align-middle mb-0 table-stack">
            <thead class="table-light"><tr><th>File</th><th>Made</th><th>By</th><th class="text-end">Lines</th><th class="text-end">Amount issued</th><th class="text-end no-print"></th></tr></thead>
            <tbody>
            @forelse ($batches as $b)
                <tr>
                    <td data-label="File"><a href="{{ route('positive-pay.show', $b) }}">#{{ $b->id }}</a> <span class="small text-secondary">{{ $b->account->name }}</span></td>
                    <td data-label="Made">{{ $b->created_at->format('d-m-Y H:i') }}</td>
                    <td data-label="By">{{ $b->creator?->name }}</td>
                    <td data-label="Lines" class="text-end">{{ $b->item_count }}</td>
                    <td data-label="Amount issued" class="amount">{{ $m($b->total) }}</td>
                    <td data-label="" class="text-end no-print text-nowrap">
                        <a href="{{ route('positive-pay.show', [$b, 'export' => 'csv']) }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-filetype-csv"></i> CSV</a>
                        <a href="{{ route('positive-pay.show', [$b, 'export' => 'xlsx']) }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-file-earmark-excel"></i> Excel</a>
                    </td>
                </tr>
            @empty
                <tr><td colspan="6" class="text-center text-secondary py-4">No files yet.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
@endif
@endsection
