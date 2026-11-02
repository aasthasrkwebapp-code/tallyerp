@extends('layouts.erp')

@section('title', 'Outstanding: '.$ledger->name)

@section('content')
<div class="page-header no-print">
    <div>
        <h1>{{ $ledger->name }}</h1>
        <p class="text-secondary mb-0">{{ $type === 'payable' ? 'Payable' : 'Receivable' }} bills as on {{ \Illuminate\Support\Carbon::parse($to)->format('d M Y') }}</p>
    </div>
    <div class="d-flex gap-2">
        <a href="{{ route('reports.outstanding', ['type' => $type, 'to' => $to]) }}" class="btn btn-outline-secondary">All parties</a>
        <a href="{{ route('reports.ledger', ['ledger' => $ledger->id]) }}" class="btn btn-outline-secondary"><i class="bi bi-book me-1"></i>Ledger</a>
    </div>
</div>

@include('reports._period', ['from' => $to, 'to' => $to, 'single' => true, 'csv' => $ledger->maintain_bill_wise])

<div class="erp-card">
    <div class="table-responsive">
        <table class="table table-sm align-middle mb-0 table-stack">
            <thead class="table-light"><tr><th>Bill</th><th>Date</th><th>Due date</th><th class="text-end">Overdue days</th><th class="text-end">Bill amount</th><th class="text-end">Pending</th></tr></thead>
            <tbody>
            @if (! $ledger->maintain_bill_wise)
                <tr><td colspan="6" class="text-secondary">This ledger does not keep bill-wise details; only the balance is shown.</td></tr>
            @endif
            @foreach ($bills as $bill)
                <tr>
                    <td data-label="Bill">@if ($bill->voucher_id)<a href="{{ route('vouchers.show', $bill->voucher_id) }}">{{ $bill->bill_ref }}</a>@else{{ $bill->bill_ref }} <span class="badge text-bg-light">Opening</span>@endif</td>
                    <td data-label="Date">{{ \Illuminate\Support\Carbon::parse($bill->date)->format('d-m-Y') }}</td>
                    <td data-label="Due date">{{ $bill->due_date ? \Illuminate\Support\Carbon::parse($bill->due_date)->format('d-m-Y') : '—' }}</td>
                    <td data-label="Overdue days" class="text-end {{ $bill->days > 0 ? 'text-danger fw-semibold' : '' }}">{{ $bill->days ?: '—' }}</td>
                    <td data-label="Bill amount" class="amount">{{ \App\Support\Money::format($bill->original, paiseInput: true) }}</td>
                    <td data-label="Pending" class="amount">{{ \App\Support\Money::format($bill->amount, paiseInput: true) }}</td>
                </tr>
            @endforeach
            @if ($onAccount !== 0)
                <tr><td data-label="Bill" colspan="5">On account / opening balance</td><td data-label="Pending" class="amount">{{ \App\Support\Money::format($onAccount, paiseInput: true) }}</td></tr>
            @endif
            </tbody>
            <tfoot class="table-light fw-bold">
                <tr><td data-label="" colspan="5">Total outstanding</td><td data-label="Total" class="amount">{{ \App\Support\Money::format($balance, paiseInput: true) }}</td></tr>
            </tfoot>
        </table>
    </div>
</div>
@endsection
