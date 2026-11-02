@extends('layouts.erp')

@php($title = $type === 'payable' ? 'Payables (Sundry Creditors)' : 'Receivables (Sundry Debtors)')
@php($fmt = fn ($p) => \App\Services\Reports\Outstanding::format($p))

@section('title', $title)

@section('content')
<div class="page-header no-print">
    <div>
        <h1>{{ $title }}</h1>
        <p class="text-secondary mb-0">Outstanding by party, aged by due date (bill date when there is no due date).</p>
    </div>
    <div class="btn-group" role="group" aria-label="Report">
        <a href="{{ route('reports.outstanding', ['type' => 'receivable', 'to' => $to]) }}" class="btn btn-outline-primary {{ $type === 'receivable' ? 'active' : '' }}">Receivables</a>
        <a href="{{ route('reports.outstanding', ['type' => 'payable', 'to' => $to]) }}" class="btn btn-outline-primary {{ $type === 'payable' ? 'active' : '' }}">Payables</a>
    </div>
</div>

@component('reports._period', ['from' => $to, 'to' => $to, 'single' => true, 'csv' => true])
    <div class="col-12 col-md-3">
        <label class="form-label small" for="q">Party</label>
        <input id="q" name="q" value="{{ $q }}" class="form-control form-control-sm" placeholder="Search name">
    </div>
@endcomponent

<div class="row g-3 mb-3">
    <div class="col-6 col-md-3"><div class="erp-card p-3"><div class="small text-secondary">Total outstanding</div><div class="fs-5 fw-bold">{{ \App\Support\Money::format($totals['balance'], true, true) }}</div></div></div>
    <div class="col-6 col-md-3"><div class="erp-card p-3"><div class="small text-secondary">Overdue</div><div class="fs-5 fw-bold text-danger">{{ \App\Support\Money::format($totals['overdue'], true, true) }}</div></div></div>
    <div class="col-6 col-md-3"><div class="erp-card p-3"><div class="small text-secondary">Not yet due</div><div class="fs-5 fw-bold">{{ \App\Support\Money::format($totals['not_due'], true, true) }}</div></div></div>
    <div class="col-6 col-md-3"><div class="erp-card p-3"><div class="small text-secondary">On account / opening</div><div class="fs-5 fw-bold">{{ \App\Support\Money::format($totals['on_account'], true, true) }}</div></div></div>
</div>

<div class="erp-card">
    <div class="table-responsive">
        <table class="table table-hover table-sm align-middle mb-0 table-stack">
            <thead class="table-light">
                <tr><th>Party</th><th class="text-end">Outstanding</th>
                    @foreach (\App\Services\Reports\Outstanding::BUCKETS as $label)<th class="text-end">{{ $label }}</th>@endforeach
                    <th class="text-end">On account</th></tr>
            </thead>
            <tbody>
            @forelse ($rows as $row)
                <tr>
                    <td data-label="Party"><a href="{{ route('reports.outstanding.party', ['ledger' => $row->account->id, 'to' => $to]) }}">{{ $row->account->name }}</a></td>
                    <td data-label="Outstanding" class="amount fw-semibold">{{ $fmt($row->balance) }}</td>
                    @foreach (\App\Services\Reports\Outstanding::BUCKETS as $key => $label)
                        <td data-label="{{ $label }}" class="amount {{ $key !== 'not_due' && $row->{$key} > 0 ? 'text-danger' : '' }}">{{ $fmt($row->{$key}) }}</td>
                    @endforeach
                    <td data-label="On account" class="amount">{{ $fmt($row->on_account) }}</td>
                </tr>
            @empty
                <tr><td colspan="8" class="text-center text-secondary py-4">Nothing outstanding.</td></tr>
            @endforelse
            </tbody>
            @if ($rows->isNotEmpty())
                <tfoot class="table-light fw-bold">
                    <tr>
                        <td data-label="">Total</td>
                        <td data-label="Outstanding" class="amount">{{ $fmt($totals['balance']) }}</td>
                        @foreach (\App\Services\Reports\Outstanding::BUCKETS as $key => $label)<td data-label="{{ $label }}" class="amount">{{ $fmt($totals[$key]) }}</td>@endforeach
                        <td data-label="On account" class="amount">{{ $fmt($totals['on_account']) }}</td>
                    </tr>
                </tfoot>
            @endif
        </table>
    </div>
</div>
<p class="small text-secondary mt-2">Negative figures are advances (for receivables, money received before billing; for payables, money paid in advance).</p>
@endsection
