@extends('layouts.erp')

@section('title', 'Bank Reconciliation')

@section('content')
@php
    $m = fn ($v) => inr((float) $v, 2);
    $drcr = fn (float $v) => inr(abs($v), 2).' '.($v < 0 ? 'Cr' : 'Dr');
@endphp
<div class="container-fluid py-3">
    @include('reports._header', [
        'title' => 'Bank Reconciliation',
        'subtitle' => ($account ? $account->name.' · ' : '').'As on '.$to->format('d-m-Y'),
    ])

    @if (session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif
    @if ($errors->any())
        <div class="alert alert-danger">
            <ul class="mb-0">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @if ($banks->isEmpty())
        <div class="alert alert-warning">
            Create a ledger under Bank Accounts first.
            <a href="{{ route('companies.accounts.create', $company) }}">Create ledger</a>
        </div>
    @else
        <form method="GET" class="row g-2 mb-3 d-print-none">
            <div class="col-12 col-md-4">
                <label class="form-label small text-secondary mb-1" for="account">Bank</label>
                <select id="account" name="account" class="form-select" required>
                    <option value="">Select bank</option>
                    @foreach ($banks as $bank)
                        <option value="{{ $bank->id }}" @selected($account?->id === $bank->id)>{{ $bank->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-6 col-md-3">
                <label class="form-label small text-secondary mb-1" for="to">As on</label>
                <input type="date" id="to" name="to" value="{{ $to->toDateString() }}" class="form-control">
            </div>
            <div class="col-6 col-md-3">
                <label class="form-label small text-secondary mb-1" for="show">Show</label>
                <select id="show" name="show" class="form-select">
                    <option value="pending" @selected(! $showAll)>Not yet cleared</option>
                    <option value="all" @selected($showAll)>All entries</option>
                </select>
            </div>
            <div class="col-12 col-md-2 d-grid align-items-end">
                <button class="btn btn-outline-primary">Show</button>
            </div>
        </form>
    @endif

    @if ($account)
        <div class="row g-3 mb-3">
            <div class="col-6 col-lg-3">
                <div class="card border-0 shadow-sm h-100"><div class="card-body">
                    <div class="small text-secondary">Balance as per books</div>
                    <div class="h5 mb-0">{{ $drcr($summary['books']) }}</div>
                </div></div>
            </div>
            <div class="col-6 col-lg-3">
                <div class="card border-0 shadow-sm h-100"><div class="card-body">
                    <div class="small text-secondary">Deposits not yet credited by bank</div>
                    <div class="h5 mb-0">− {{ $m($summary['not_in_bank_debits']) }}</div>
                </div></div>
            </div>
            <div class="col-6 col-lg-3">
                <div class="card border-0 shadow-sm h-100"><div class="card-body">
                    <div class="small text-secondary">Cheques issued, not yet presented</div>
                    <div class="h5 mb-0">+ {{ $m($summary['not_in_bank_credits']) }}</div>
                </div></div>
            </div>
            <div class="col-6 col-lg-3">
                <div class="card border-0 shadow-sm h-100"><div class="card-body">
                    <div class="small text-secondary">Balance as per bank</div>
                    <div class="h5 mb-0">{{ $drcr($summary['bank']) }}</div>
                </div></div>
            </div>
        </div>

        <form method="POST" action="{{ route('companies.banking.reconciliation.update', $company) }}">
            @csrf
            @method('PUT')
            <input type="hidden" name="account_id" value="{{ $account->id }}">

            <div class="card border-0 shadow-sm">
                <div class="table-responsive">
                    <table class="table align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Date</th>
                                <th>Particulars</th>
                                <th>Voucher</th>
                                <th style="width: 160px">Cheque / UTR</th>
                                <th class="text-end">Deposit</th>
                                <th class="text-end">Withdrawal</th>
                                <th style="width: 170px">Bank date</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($entries as $entry)
                                <tr>
                                    <td>{{ $entry->voucher->date->format('d-m-Y') }}</td>
                                    <td>{{ $entry->voucher->entries->where('account_id', '!=', $account->id)->pluck('account.name')->join(', ') }}</td>
                                    <td>
                                        <a href="{{ route('companies.vouchers.show', [$company, $entry->voucher]) }}">
                                            {{ $entry->voucher->typeLabel() }} #{{ $entry->voucher->number }}
                                        </a>
                                    </td>
                                    <td>
                                        <input name="entries[{{ $entry->id }}][instrument_no]" value="{{ old("entries.$entry->id.instrument_no", $entry->instrument_no) }}"
                                            class="form-control form-control-sm" maxlength="50">
                                    </td>
                                    <td class="text-end">{{ $entry->debit > 0 ? $m($entry->debit) : '' }}</td>
                                    <td class="text-end">{{ $entry->credit > 0 ? $m($entry->credit) : '' }}</td>
                                    <td>
                                        <input type="date" name="entries[{{ $entry->id }}][bank_date]"
                                            value="{{ old("entries.$entry->id.bank_date", $entry->bank_date?->toDateString()) }}"
                                            class="form-control form-control-sm">
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="7" class="text-center text-secondary py-4">Everything is reconciled.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                @if ($entries->isNotEmpty())
                    <div class="card-footer bg-white text-end">
                        <button class="btn btn-primary">Save Reconciliation</button>
                    </div>
                @endif
            </div>
        </form>
    @endif
</div>
@endsection
