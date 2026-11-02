@extends('layouts.erp')

@section('title', 'Positive pay file #'.$batch->id)

@section('content')
<div class="page-header">
    <div>
        <h1>Positive pay file #{{ $batch->id }}</h1>
        <p class="text-secondary mb-0">{{ $batch->account->name }} · account {{ $batch->account->bank_account_number }} · made {{ $batch->created_at->format('d-m-Y H:i') }} by {{ $batch->creator?->name }} · cheques of ₹{{ \App\Support\Money::format($batch->threshold) }} and above</p>
    </div>
    <div class="d-flex flex-wrap gap-2 no-print">
        <a href="{{ route('positive-pay.show', [$batch, 'export' => 'csv']) }}" class="btn btn-primary"><i class="bi bi-filetype-csv me-1"></i>CSV</a>
        <a href="{{ route('positive-pay.show', [$batch, 'export' => 'xlsx']) }}" class="btn btn-outline-secondary"><i class="bi bi-file-earmark-excel me-1"></i>Excel</a>
        <a href="{{ route('positive-pay.index', ['bank' => $batch->account_id]) }}" class="btn btn-outline-secondary">Positive pay</a>
    </div>
</div>
<p class="small text-secondary">Upload the file in the bank's positive pay screen, or use its columns with the bank's own template. Action I = issued, C = cancelled. The details are kept as they were sent.</p>
<div class="erp-card">
    <div class="table-responsive">
        <table class="table table-sm align-middle mb-0 table-stack">
            <thead class="table-light"><tr><th>Action</th><th>Cheque</th><th>Date</th><th>Payee</th><th class="text-end">Amount</th><th>Voucher</th></tr></thead>
            <tbody>
            @foreach ($batch->items->sortBy([['action', 'desc'], ['cheque_number', 'asc']]) as $i)
                <tr>
                    <td data-label="Action">{{ \App\Models\PositivePayItem::ACTIONS[$i->action] }}</td>
                    <td data-label="Cheque">{{ $i->cheque_number }}</td>
                    <td data-label="Date">{{ $i->cheque_date->format('d-m-Y') }}</td>
                    <td data-label="Payee">{{ $i->payee_name }}</td>
                    <td data-label="Amount" class="amount">{{ \App\Support\Money::format($i->amount) }}</td>
                    <td data-label="Voucher">@if ($i->voucher)<a href="{{ route('vouchers.show', $i->voucher) }}">{{ $i->voucher->displayNumber() }}</a>@else <span class="text-secondary">deleted</span>@endif</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
</div>
@endsection
