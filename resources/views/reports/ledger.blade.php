@extends('layouts.erp')

@php($title = $book === 'cash' ? 'Cash Book' : ($book === 'bank' ? 'Bank Book' : 'Ledger Statement'))
@section('title', $title)

@section('content')
<div class="page-header">
    <div>
        <h1>{{ $title }}{{ $ledger ? ': '.$ledger->name : '' }}</h1>
        @if ($ledger)<p class="text-secondary mb-0">{{ $ledger->group->path() }}</p>@endif
    </div>
    <div class="btn-group no-print" role="group" aria-label="Book">
        <a href="{{ route('reports.ledger', ['from' => $from, 'to' => $to]) }}" class="btn btn-outline-secondary {{ ! $book ? 'active' : '' }}">Ledger</a>
        <a href="{{ route('reports.ledger', ['book' => 'cash', 'from' => $from, 'to' => $to]) }}" class="btn btn-outline-secondary {{ $book === 'cash' ? 'active' : '' }}">Cash book</a>
        <a href="{{ route('reports.ledger', ['book' => 'bank', 'from' => $from, 'to' => $to]) }}" class="btn btn-outline-secondary {{ $book === 'bank' ? 'active' : '' }}">Bank book</a>
    </div>
</div>

@component('reports._period', ['from' => $from, 'to' => $to, 'csv' => (bool) $ledger])
    <div class="col-12 col-md-4">
        <label class="form-label small" for="r-ledger">Ledger</label>
        <select id="r-ledger" name="ledger" class="form-select form-select-sm" onchange="this.form.submit()">
            <option value="">Select ledger…</option>
            @foreach ($ledgers as $l)<option value="{{ $l->id }}" @selected($ledger?->id === $l->id)>{{ $l->name }}</option>@endforeach
        </select>
    </div>
@endcomponent

@if ($ledger)
    <div class="erp-card">
        <div class="table-responsive">
            <table class="table table-sm table-report align-middle mb-0 table-stack">
                <thead class="table-light"><tr><th>Date</th><th>Particulars</th><th>Type</th><th>Number</th><th class="text-end">Debit</th><th class="text-end">Credit</th><th class="text-end">Balance</th></tr></thead>
                <tbody>
                    <tr class="row-group">
                        <td data-label="Date">{{ \Illuminate\Support\Carbon::parse($from)->format('d M Y') }}</td>
                        <td data-label="Particulars" colspan="5">Opening balance</td>
                        <td data-label="Balance" class="amount">{{ \App\Support\Money::drCr($opening) }}</td>
                    </tr>
                    @forelse ($lines as $line)
                        <tr>
                            <td data-label="Date" class="text-nowrap">{{ $line->voucher->date->format('d M Y') }}</td>
                            <td data-label="Particulars">
                                {{ $line->particulars }}
                                @if ($line->entry->narration ?? $line->voucher->narration)<div class="small text-secondary">{{ Str::limit($line->entry->narration ?? $line->voucher->narration, 80) }}</div>@endif
                            </td>
                            <td data-label="Type">{{ $line->voucher->type->name }}</td>
                            <td data-label="Number"><a href="{{ route('vouchers.show', $line->voucher) }}">{{ $line->voucher->voucher_number }}</a></td>
                            <td data-label="Debit" class="amount">{{ $line->debit ? \App\Support\Money::format($line->debit, paiseInput: true) : '' }}</td>
                            <td data-label="Credit" class="amount">{{ $line->credit ? \App\Support\Money::format($line->credit, paiseInput: true) : '' }}</td>
                            <td data-label="Balance" class="amount">{{ \App\Support\Money::drCr($line->balance) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="text-center text-secondary py-3">No transactions in this period.</td></tr>
                    @endforelse
                </tbody>
                <tfoot>
                    <tr class="row-total">
                        <td colspan="4" data-label="">Totals and closing balance</td>
                        <td class="amount" data-label="Debit">{{ \App\Support\Money::format($totalDr, paiseInput: true) }}</td>
                        <td class="amount" data-label="Credit">{{ \App\Support\Money::format($totalCr, paiseInput: true) }}</td>
                        <td class="amount" data-label="Closing">{{ \App\Support\Money::drCr($closing) }}</td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
@else
    <div class="erp-card p-4 text-center text-secondary">Select a ledger to see its statement.</div>
@endif
@endsection
