@extends('layouts.erp')

@section('title', $ledger->name)

@section('content')
@php($d = $ledger->partyDetails)
<div class="page-header">
    <div>
        <h1>{{ $ledger->name }} @unless ($ledger->is_active)<span class="badge text-bg-secondary">Inactive</span>@endunless</h1>
        <p class="text-secondary mb-0">{{ $labels['singular'] }} · {{ $ledger->group->name }}</p>
    </div>
    <div class="d-flex flex-wrap gap-2">
        @can($labels['module'].'.create')
            <a href="{{ route('invoices.create', ['kind' => $labels['invoice'], 'party' => $ledger->id]) }}" class="btn btn-primary"><i class="bi bi-receipt me-1"></i>New {{ $kind === 'customer' ? 'invoice' : 'bill' }}</a>
        @endcan
        @can('reports.view')
            <a href="{{ route('reports.ledger', ['ledger' => $ledger->id]) }}" class="btn btn-outline-secondary"><i class="bi bi-book me-1"></i>Ledger</a>
        @endcan
        @can($labels['module'].'.edit')
            <a href="{{ route($labels['route'].'.edit', $ledger) }}" class="btn btn-outline-primary"><i class="bi bi-pencil me-1"></i>Edit</a>
        @endcan
        @can($labels['module'].'.delete')
            <form method="POST" action="{{ route($labels['route'].'.destroy', $ledger) }}" data-confirm="Delete {{ $ledger->name }}?">@csrf @method('DELETE')<button class="btn btn-outline-danger"><i class="bi bi-trash"></i><span class="visually-hidden">Delete</span></button></form>
        @endcan
    </div>
</div>

<div class="row g-3 mb-3">
    <div class="col-12 col-lg-4">
        <div class="erp-card p-3 h-100">
            <div class="text-secondary small">Balance</div>
            <div class="fs-4 fw-bold">{{ \App\Support\Money::drCr($balance) }}</div>
            @if ((float) $ledger->credit_limit)
                <div class="small {{ $balance > \App\Support\Money::toPaise($ledger->credit_limit) ? 'text-danger fw-semibold' : 'text-secondary' }}">Credit limit {{ \App\Support\Money::format($ledger->credit_limit) }}</div>
            @endif
            @if ($ledger->credit_period_days)<div class="small text-secondary">Credit period {{ $ledger->credit_period_days }} days</div>@endif
        </div>
    </div>
    <div class="col-12 col-lg-8">
        <div class="erp-card p-3 h-100">
            <div class="row g-2 small">
                <div class="col-6 col-md-4"><div class="text-secondary">GST registration</div>{{ config('erp.gst_registration_types')[$d?->gst_registration_type] ?? '—' }}</div>
                <div class="col-6 col-md-4"><div class="text-secondary">GSTIN</div><span class="font-monospace">{{ $d?->gstin ?? '—' }}</span></div>
                <div class="col-6 col-md-4"><div class="text-secondary">PAN</div><span class="font-monospace">{{ $d?->pan ?? '—' }}</span></div>
                <div class="col-6 col-md-4"><div class="text-secondary">Contact</div>{{ $d?->contact_person ?? '—' }}</div>
                <div class="col-6 col-md-4"><div class="text-secondary">Phone</div>{{ $d?->phone ?? '—' }}</div>
                <div class="col-6 col-md-4"><div class="text-secondary">Email</div>{{ $d?->email ?? '—' }}</div>
                <div class="col-12 col-md-6"><div class="text-secondary">Billing address</div><span style="white-space: pre-line">{{ $d?->fullAddress() ?: '—' }}</span></div>
                <div class="col-12 col-md-6"><div class="text-secondary">Shipping address</div><span style="white-space: pre-line">{{ $d?->shipping_address ?: 'Same as billing' }}</span></div>
            </div>
            @unless ($d)
                <div class="alert alert-info small mt-2 mb-0">No contact or GST details yet. Use <strong>Edit</strong> to add them.</div>
            @endunless
        </div>
    </div>
</div>

@if ($ledger->maintain_bill_wise)
    <div class="erp-card mb-3">
        <div class="p-3 pb-0 d-flex justify-content-between align-items-center">
            <h2 class="h6 fw-bold mb-0">Pending bills</h2>
            @can('reports.view')<a href="{{ route('reports.outstanding.party', $ledger) }}" class="small">Full statement</a>@endcan
        </div>
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0 table-stack">
                <thead class="table-light"><tr><th>Bill</th><th>Date</th><th>Due</th><th class="text-end">Overdue days</th><th class="text-end">Pending</th></tr></thead>
                <tbody>
                @forelse ($bills as $bill)
                    <tr>
                        <td data-label="Bill"><a href="{{ route('vouchers.show', $bill->voucher_id) }}">{{ $bill->bill_ref }}</a></td>
                        <td data-label="Date">{{ \Illuminate\Support\Carbon::parse($bill->date)->format('d-m-Y') }}</td>
                        <td data-label="Due">{{ $bill->due_date ? \Illuminate\Support\Carbon::parse($bill->due_date)->format('d-m-Y') : '—' }}</td>
                        <td data-label="Overdue" class="text-end {{ $bill->days > 0 ? 'text-danger' : '' }}">{{ $bill->days ?: '—' }}</td>
                        <td data-label="Pending" class="amount">{{ \App\Support\Money::format($bill->amount, paiseInput: true) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="text-center text-secondary py-3">No pending bills.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endif

<div class="erp-card">
    <div class="p-3 pb-0"><h2 class="h6 fw-bold mb-0">Recent documents</h2></div>
    <div class="table-responsive">
        <table class="table table-sm align-middle mb-0 table-stack">
            <thead class="table-light"><tr><th>Document</th><th>Date</th><th>Status</th><th class="text-end">Amount</th></tr></thead>
            <tbody>
            @forelse ($invoices as $inv)
                <tr>
                    <td data-label="Document"><a href="{{ route('invoices.show', $inv) }}">{{ $inv->config()['label'] }} {{ $inv->displayNumber() }}</a></td>
                    <td data-label="Date">{{ $inv->date->format('d-m-Y') }}</td>
                    <td data-label="Status">@include('accounting.vouchers._status', ['status' => $inv->status()])</td>
                    <td data-label="Amount" class="amount">{{ \App\Support\Money::format($inv->grand_total) }}</td>
                </tr>
            @empty
                <tr><td colspan="4" class="text-center text-secondary py-3">No invoices yet.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
