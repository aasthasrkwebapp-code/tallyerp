@extends('layouts.erp')

@php($M = \App\Support\Money::class)

@section('title', 'Bank Reconciliation')

@section('content')
<div class="page-header">
    <div>
        <h1>Bank reconciliation</h1>
        <p class="text-secondary mb-0">Enter the date each entry cleared the bank, or import the bank statement and match it.</p>
    </div>
</div>

@if (! $bank)
    <div class="erp-card p-4 text-center text-secondary">
        No bank ledgers yet. Create a ledger under <strong>Bank Accounts</strong> first.
        @can('masters.create')<div class="mt-2"><a href="{{ route('ledgers.create') }}" class="btn btn-primary btn-sm">New ledger</a></div>@endcan
    </div>
@else
<form method="GET" class="erp-card p-3 mb-3">
    <div class="row g-2 align-items-end">
        <div class="col-12 col-md-4">
            <label class="form-label small" for="bank">Bank</label>
            <select id="bank" name="bank" class="form-select form-select-sm" onchange="this.form.submit()">
                @foreach ($banks as $b)<option value="{{ $b->id }}" @selected($b->id === $bank->id)>{{ $b->name }}</option>@endforeach
            </select>
        </div>
        <div class="col-6 col-md-2">
            <label class="form-label small" for="to">As on</label>
            <input id="to" type="date" name="to" value="{{ $to }}" class="form-control form-control-sm">
        </div>
        <div class="col-6 col-md-2">
            <label class="form-label small" for="from">From <small class="text-secondary">(with "all")</small></label>
            <input id="from" type="date" name="from" value="{{ $from }}" class="form-control form-control-sm">
        </div>
        <div class="col-6 col-md-2">
            <div class="form-check">
                <input class="form-check-input" type="checkbox" name="all" value="1" id="all" @checked($all)>
                <label class="form-check-label small" for="all">Show reconciled too</label>
            </div>
        </div>
        <div class="col-6 col-md-2"><button class="btn btn-sm btn-primary w-100">Show</button></div>
    </div>
</form>

<div class="row g-3 mb-3">
    <div class="col-6 col-lg-3"><div class="erp-card p-3 h-100"><div class="small text-secondary">Balance as per books</div><div class="fs-5 fw-bold">{{ $M::drCr($summary['book']) }}</div></div></div>
    <div class="col-6 col-lg-3"><div class="erp-card p-3 h-100"><div class="small text-secondary">Deposits not yet in bank</div><div class="fs-5 fw-bold">{{ $M::format($summary['uncleared_deposits'], paiseInput: true) }}</div></div></div>
    <div class="col-6 col-lg-3"><div class="erp-card p-3 h-100"><div class="small text-secondary">Payments not yet in bank</div><div class="fs-5 fw-bold">{{ $M::format($summary['uncleared_withdrawals'], paiseInput: true) }}</div></div></div>
    <div class="col-6 col-lg-3"><div class="erp-card p-3 h-100"><div class="small text-secondary">Balance as per bank</div><div class="fs-5 fw-bold text-primary">{{ $M::drCr($summary['bank']) }}</div></div></div>
</div>

<form method="POST" action="{{ route('bank-reconciliation.update', $bank) }}" class="erp-card mb-4">
    @csrf
    <div class="erp-card-header d-flex justify-content-between align-items-center">
        <span>{{ $all ? 'Entries' : 'Uncleared entries' }} up to {{ \Illuminate\Support\Carbon::parse($to)->format('d M Y') }}</span>
        @can('banking.edit')<button class="btn btn-sm btn-primary" data-shortcut="s" @disabled($entries->isEmpty())>Save bank dates</button>@endcan
    </div>
    <div class="table-responsive">
        <table class="table table-sm align-middle mb-0 table-stack">
            <thead class="table-light"><tr><th>Date</th><th>Voucher</th><th>Particulars</th><th>Instrument</th><th class="text-end">Deposit</th><th class="text-end">Withdrawal</th><th style="width: 170px">Bank date</th></tr></thead>
            <tbody>
            @forelse ($entries as $entry)
                @php($v = $entry->voucher)
                <tr>
                    <td data-label="Date">{{ $v->date->format('d-m-Y') }}</td>
                    <td data-label="Voucher"><a href="{{ route('vouchers.show', $v) }}">{{ $v->type->name }} {{ $v->voucher_number }}</a></td>
                    <td data-label="Particulars">{{ $v->entries->where('id', '!=', $entry->id)->pluck('account.name')->unique()->implode(', ') }}</td>
                    <td data-label="Instrument">{{ $v->reference ?? '—' }}</td>
                    <td data-label="Deposit" class="amount">{{ (float) $entry->debit ? $M::format($entry->debit) : '' }}</td>
                    <td data-label="Withdrawal" class="amount">{{ (float) $entry->credit ? $M::format($entry->credit) : '' }}</td>
                    <td data-label="Bank date">
                        <input type="date" name="dates[{{ $entry->id }}]" value="{{ $entry->bank_date?->toDateString() }}" min="{{ $v->date->toDateString() }}"
                            class="form-control form-control-sm @error('dates.'.$entry->id) is-invalid @enderror" aria-label="Bank date for {{ $v->voucher_number }}" @cannot('banking.edit') disabled @endcannot>
                    </td>
                </tr>
            @empty
                <tr><td colspan="7" class="text-center text-secondary py-4">Everything up to this date has cleared the bank.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</form>

<div class="erp-card">
    <div class="erp-card-header d-flex flex-wrap gap-2 justify-content-between align-items-center">
        <span>Bank statement <span class="badge text-bg-warning">{{ $unmatchedCount }} unmatched</span></span>
        <div class="d-flex flex-wrap gap-2">
            <div class="btn-group btn-group-sm">
                <a href="{{ request()->fullUrlWithQuery(['statement' => 'unmatched']) }}" class="btn btn-outline-secondary {{ $statementFilter === 'unmatched' ? 'active' : '' }}">Unmatched</a>
                <a href="{{ request()->fullUrlWithQuery(['statement' => 'all']) }}" class="btn btn-outline-secondary {{ $statementFilter === 'all' ? 'active' : '' }}">All</a>
            </div>
            @can('banking.edit')
                <form method="POST" action="{{ route('bank-reconciliation.auto-match', $bank) }}">@csrf<button class="btn btn-sm btn-outline-primary"><i class="bi bi-magic me-1"></i>Auto match</button></form>
            @endcan
        </div>
    </div>
    @can('banking.create')
        <form method="POST" action="{{ route('bank-reconciliation.import', $bank) }}" enctype="multipart/form-data" class="p-3 border-bottom">
            @csrf
            <div class="row g-2 align-items-end">
                <div class="col-12 col-md-6">
                    <label for="statement" class="form-label small">Import statement (CSV, OFX / QFX, MT940 or CAMT.053)</label>
                    <input id="statement" type="file" name="statement" accept=".csv,.txt,.ofx,.qfx,.sta,.mt940,.940,.xml,.camt,.053,text/csv" class="form-control form-control-sm @error('statement') is-invalid @enderror" required>
                    @error('statement')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-12 col-md-auto"><button class="btn btn-sm btn-primary">Import &amp; match</button></div>
                <div class="col-12 small text-secondary">OFX / QFX, MT940 and CAMT.053 (ISO 20022 XML) files are read as they come from the bank; importing the same file again skips lines already imported. For CSV, columns: <code>Date</code>, <code>Description</code>, <code>Reference</code>, <code>Withdrawal</code>, <code>Deposit</code> (or <code>Debit</code>/<code>Credit</code>, or one signed <code>Amount</code>). Dates as 2026-04-30, 30-04-2026 or 30/04/2026.</div>
            </div>
        </form>
    @endcan
    <div class="table-responsive">
        <table class="table table-sm align-middle mb-0 table-stack">
            <thead class="table-light"><tr><th>Date</th><th>Description</th><th>Reference</th><th class="text-end">Withdrawal</th><th class="text-end">Deposit</th><th>Matched with</th><th></th></tr></thead>
            <tbody>
            @forelse ($lines as $line)
                <tr>
                    <td data-label="Date">{{ $line->date->format('d-m-Y') }}</td>
                    <td data-label="Description">{{ $line->description }}</td>
                    <td data-label="Reference">{{ $line->reference }}</td>
                    <td data-label="Withdrawal" class="amount">{{ (float) $line->withdrawal ? $M::format($line->withdrawal) : '' }}</td>
                    <td data-label="Deposit" class="amount">{{ (float) $line->deposit ? $M::format($line->deposit) : '' }}</td>
                    <td data-label="Matched with">
                        @if ($line->entry)
                            <a href="{{ route('vouchers.show', $line->entry->voucher_id) }}">{{ $line->entry->voucher->voucher_number }}</a>
                            <span class="small text-secondary">{{ $line->entry->voucher->date->format('d-m-Y') }}</span>
                        @elseif (($candidates[$line->id] ?? collect())->isNotEmpty() && auth()->user()->can('banking.edit'))
                            <form method="POST" action="{{ route('bank-statement-lines.match', $line) }}" class="d-flex gap-1">
                                @csrf
                                <select name="entry_id" class="form-select form-select-sm" aria-label="Entry to match">
                                    @foreach ($candidates[$line->id] as $c)<option value="{{ $c->id }}">{{ $c->voucher->voucher_number }} · {{ $c->voucher->date->format('d-m') }} {{ $c->voucher->reference }}</option>@endforeach
                                </select>
                                <button class="btn btn-sm btn-outline-primary">Match</button>
                            </form>
                        @else
                            <span class="small text-secondary">No matching entry. Enter the voucher, then match.</span>
                        @endif
                    </td>
                    <td data-label="" class="text-end text-nowrap">
                        @if ($line->entry)
                            @can('banking.edit')<form method="POST" action="{{ route('bank-statement-lines.unmatch', $line) }}" class="d-inline">@csrf<button class="btn btn-sm btn-outline-secondary" aria-label="Remove match"><i class="bi bi-x-circle"></i></button></form>@endcan
                        @else
                            @can('banking.delete')<form method="POST" action="{{ route('bank-statement-lines.destroy', $line) }}" class="d-inline" data-confirm="Delete this statement line?">@csrf @method('DELETE')<button class="btn btn-sm btn-outline-danger" aria-label="Delete line"><i class="bi bi-trash"></i></button></form>@endcan
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="7" class="text-center text-secondary py-4">No statement lines {{ $statementFilter === 'unmatched' ? 'waiting to be matched' : 'imported yet' }}.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
<div class="mt-3">{{ $lines->links() }}</div>
@endif
@endsection
