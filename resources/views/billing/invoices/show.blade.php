@extends('layouts.erp')

@php($status = $invoice->status())
@php($user = auth()->user())

@section('title', $config['label'].' '.$invoice->displayNumber())

@section('content')
<div class="page-header">
    <div>
        <h1>{{ $config['label'] }} {{ $invoice->displayNumber() }} @include('accounting.vouchers._status', ['status' => $status])</h1>
        <p class="text-secondary mb-0">{{ $invoice->date->format('d M Y') }} · {{ $invoice->party_name }} · Entered by {{ $invoice->creator?->name ?? '—' }}</p>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <a href="{{ route('invoices.print', $invoice) }}" class="btn btn-outline-secondary" target="_blank" rel="noopener"><i class="bi bi-printer me-1"></i>Print</a>
        <a href="{{ route('invoices.pdf', $invoice) }}" class="btn btn-outline-secondary"><i class="bi bi-file-earmark-pdf me-1"></i>PDF</a>
        @if ($status !== 'cancelled' && $user->can($config['module'].'.edit'))
            <a href="{{ route('invoices.edit', $invoice) }}" class="btn btn-outline-primary"><i class="bi bi-pencil me-1"></i>Alter</a>
        @endif
        @if ($status === 'draft' && $user->can($config['module'].'.edit') && $user->can('vouchers.post'))
            <form method="POST" action="{{ route('invoices.post', $invoice) }}">@csrf<button class="btn btn-primary">Post</button></form>
        @endif
        @if ($status === 'pending_approval' && $user->can('vouchers.approve'))
            <form method="POST" action="{{ route('invoices.post', $invoice) }}">@csrf<button class="btn btn-success"><i class="bi bi-check2-circle me-1"></i>Approve &amp; post</button></form>
        @endif
        @if (in_array($status, ['draft', 'pending_approval'], true) && $user->can($config['module'].'.delete'))
            <form method="POST" action="{{ route('invoices.destroy', $invoice) }}" data-confirm="Delete this draft?">@csrf @method('DELETE')<button class="btn btn-outline-danger">Delete draft</button></form>
        @endif
        @if ($status === 'posted' && in_array($invoice->kind, ['sales', 'purchase'], true) && $user->can($config['module'].'.create'))
            <a href="{{ route('invoices.create', ['kind' => $invoice->kind === 'sales' ? 'credit_note' : 'debit_note', 'from' => $invoice->id]) }}" class="btn btn-outline-secondary"><i class="bi bi-arrow-return-left me-1"></i>{{ $invoice->kind === 'sales' ? 'Credit note' : 'Debit note' }}</a>
        @endif
        @if ($status === 'posted' && $user->can($config['module'].'.delete') && $user->can('vouchers.cancel'))
            <button class="btn btn-outline-danger" data-bs-toggle="collapse" data-bs-target="#cancel-panel" aria-expanded="false">Cancel</button>
        @endif
    </div>
</div>

@if ($status === 'posted')
    <div class="collapse" id="cancel-panel">
        <form method="POST" action="{{ route('invoices.cancel', $invoice) }}" class="erp-card p-3 mb-3" style="max-width: 560px" data-confirm="Cancel {{ $invoice->displayNumber() }}? It keeps its number but no longer affects any balance.">
            @csrf
            <label for="reason" class="form-label">Reason for cancelling</label>
            <input id="reason" name="reason" class="form-control mb-2" required maxlength="255">
            <button class="btn btn-danger">Cancel {{ mb_strtolower($config['label']) }}</button>
        </form>
    </div>
@endif

@if ($status === 'cancelled')
    <div class="alert alert-danger small">Cancelled {{ $invoice->voucher->cancelled_at?->format('d M Y H:i') }}: {{ $invoice->voucher->cancellation_reason }}</div>
@endif

<div class="row g-3 mb-3">
    <div class="col-12 col-lg-4">
        <div class="erp-card p-3 h-100 small">
            <div class="text-secondary">{{ $config['party_group'] === 'sundry_debtors' ? 'Bill to' : 'Supplier' }}</div>
            <div class="fw-semibold">{{ $invoice->party_name }}</div>
            <div style="white-space: pre-line">{{ $invoice->billing_address }}</div>
            @if ($invoice->party_gstin)<div>GSTIN: <span class="font-monospace">{{ $invoice->party_gstin }}</span></div>@endif
            @if ($invoice->shipping_address)
                <div class="text-secondary mt-2">Ship to</div>
                <div style="white-space: pre-line">{{ $invoice->shipping_address }}</div>
            @endif
        </div>
    </div>
    <div class="col-12 col-lg-8">
        <div class="erp-card p-3 h-100">
            <div class="row g-2 small">
                <div class="col-6 col-md-4"><div class="text-secondary">{{ $config['tax'] === 'input' ? 'Supplier state' : 'Place of supply' }}</div>{{ $invoice->place_of_supply ?? '—' }}</div>
                <div class="col-6 col-md-4"><div class="text-secondary">Tax</div>{{ ['intra' => 'CGST + SGST', 'inter' => 'IGST', 'none' => 'No GST'][$invoice->tax_mode] }}</div>
                <div class="col-6 col-md-4"><div class="text-secondary">Due date</div>{{ $invoice->due_date?->format('d M Y') ?? '—' }}</div>
                <div class="col-6 col-md-4"><div class="text-secondary">Reference</div>{{ $invoice->reference ?? '—' }} {{ $invoice->reference_date?->format('d-m-Y') }}</div>
                <div class="col-6 col-md-4"><div class="text-secondary">Voucher</div>
                    @if ($invoice->voucher)<a href="{{ route('vouchers.show', $invoice->voucher) }}">{{ $invoice->voucher->type->name }} {{ $invoice->voucher->displayNumber() }}</a>@else — @endif
                </div>
                @if ($invoice->originalInvoice)
                    <div class="col-6 col-md-4"><div class="text-secondary">Against</div><a href="{{ route('invoices.show', $invoice->originalInvoice) }}">{{ $invoice->originalInvoice->displayNumber() }}</a></div>
                @endif
            </div>
            @if ($returns->isNotEmpty())
                <div class="small mt-2"><span class="text-secondary">Returns:</span>
                    @foreach ($returns as $r)<a href="{{ route('invoices.show', $r) }}" class="me-2">{{ $r->displayNumber() }} ({{ \App\Support\Money::format($r->grand_total) }}, {{ \App\Models\Voucher::STATUSES[$r->status()] }})</a>@endforeach
                </div>
            @endif
        </div>
    </div>
</div>

<div class="erp-card mb-3">
    <div class="table-responsive">
        <table class="table align-middle mb-0 table-stack">
            <thead class="table-light"><tr><th>#</th><th>Description</th><th>HSN/SAC</th><th class="text-end">Qty</th><th class="text-end">Rate</th><th class="text-end">Disc %</th><th class="text-end">Taxable</th><th class="text-end">GST %</th><th class="text-end">Tax</th><th class="text-end">Amount</th></tr></thead>
            <tbody>
            @foreach ($invoice->items as $item)
                <tr>
                    <td data-label="#">{{ $item->line_no }}</td>
                    <td data-label="Description">{{ $item->description }}<div class="small text-secondary">{{ $item->account->name }}</div>@include('inventory._tracking', ['line' => $item])</td>
                    <td data-label="HSN/SAC">{{ $item->hsn_sac ?? '—' }}</td>
                    <td data-label="Qty" class="text-end">{{ \App\Support\Decimal::trim($item->quantity) }} {{ $item->unit }}</td>
                    <td data-label="Rate" class="amount">{{ \App\Support\Money::format($item->rate) }}</td>
                    <td data-label="Disc %" class="text-end">{{ (float) $item->discount_percent ? \App\Support\Decimal::trim($item->discount_percent) : '—' }}</td>
                    <td data-label="Taxable" class="amount">{{ \App\Support\Money::format($item->taxable_amount) }}</td>
                    <td data-label="GST %" class="text-end">{{ \App\Support\Decimal::trim($item->gst_rate) }}</td>
                    <td data-label="Tax" class="amount">{{ \App\Support\Money::format(\App\Support\Money::toPaise($item->cgst_amount) + \App\Support\Money::toPaise($item->sgst_amount) + \App\Support\Money::toPaise($item->igst_amount), paiseInput: true) }}</td>
                    <td data-label="Amount" class="amount fw-semibold">{{ \App\Support\Money::format($item->total_amount) }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
</div>

<div class="row g-3">
    <div class="col-12 col-lg-7">
        @if ($invoice->tax_mode !== 'none')
            <div class="erp-card mb-3">
                <div class="erp-card-header">Tax summary</div>
                <div class="table-responsive">
                    <table class="table table-sm mb-0 table-stack small">
                        <thead class="table-light"><tr><th>HSN/SAC</th><th class="text-end">Rate</th><th class="text-end">Taxable</th>@if ($invoice->tax_mode === 'intra')<th class="text-end">CGST</th><th class="text-end">SGST</th>@else<th class="text-end">IGST</th>@endif</tr></thead>
                        <tbody>
                        @foreach ($taxSummary as $row)
                            <tr>
                                <td data-label="HSN/SAC">{{ $row['hsn'] ?? '—' }}</td>
                                <td data-label="Rate" class="text-end">{{ $row['rate'] }}%</td>
                                <td data-label="Taxable" class="amount">{{ \App\Support\Money::format($row['taxable'], paiseInput: true) }}</td>
                                @if ($invoice->tax_mode === 'intra')
                                    <td data-label="CGST" class="amount">{{ \App\Support\Money::format($row['cgst'], paiseInput: true) }}</td>
                                    <td data-label="SGST" class="amount">{{ \App\Support\Money::format($row['sgst'], paiseInput: true) }}</td>
                                @else
                                    <td data-label="IGST" class="amount">{{ \App\Support\Money::format($row['igst'], paiseInput: true) }}</td>
                                @endif
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endif
        @if ($invoice->narration)<div class="erp-card p-3 mb-3 small"><div class="text-secondary">Narration</div>{{ $invoice->narration }}</div>@endif
        @if ($invoice->terms)<div class="erp-card p-3 mb-3 small"><div class="text-secondary">Terms</div><span style="white-space: pre-line">{{ $invoice->terms }}</span></div>@endif
    </div>
    <div class="col-12 col-lg-5">
        <div class="erp-card p-3">
            <table class="table table-sm mb-0">
                <tr><td>Gross</td><td class="amount">{{ \App\Support\Money::format($invoice->gross_total) }}</td></tr>
                @if ((float) $invoice->discount_total)<tr><td>Discount</td><td class="amount">-{{ \App\Support\Money::format($invoice->discount_total) }}</td></tr>@endif
                <tr><td>Taxable value</td><td class="amount">{{ \App\Support\Money::format($invoice->taxable_total) }}</td></tr>
                @if ((float) $invoice->cgst_total)<tr><td>CGST</td><td class="amount">{{ \App\Support\Money::format($invoice->cgst_total) }}</td></tr>@endif
                @if ((float) $invoice->sgst_total)<tr><td>SGST / UTGST</td><td class="amount">{{ \App\Support\Money::format($invoice->sgst_total) }}</td></tr>@endif
                @if ((float) $invoice->igst_total)<tr><td>IGST</td><td class="amount">{{ \App\Support\Money::format($invoice->igst_total) }}</td></tr>@endif
                @if ((float) $invoice->round_off)<tr><td>Round off</td><td class="amount">{{ \App\Support\Money::format($invoice->round_off) }}</td></tr>@endif
                <tr class="fw-bold fs-5"><td>Total</td><td class="amount">{{ \App\Support\Money::format($invoice->grand_total, true) }}</td></tr>
            </table>
            <div class="small text-secondary mt-2">{{ \App\Support\NumberToWords::rupees(\App\Support\Money::toPaise($invoice->grand_total)) }}</div>
        </div>
    </div>
</div>
@endsection
