@extends('layouts.erp')

@php($user = auth()->user())
@php($q = fn ($milli, $p) => \App\Services\Inventory\StockLedger::qty($milli).($p->item->unit ? ' '.$p->item->unit : ''))
@php($m = fn ($p) => \App\Support\Money::format($p, paiseInput: true))
@php($pendingTotal = $progress->sum('pending_value'))

@section('title', $config['label'].' '.$order->number)

@section('content')
<div class="page-header">
    <div>
        <h1>{{ $config['label'] }} {{ $order->number }} @include('billing.orders._status', ['label' => $label])</h1>
        <p class="text-secondary mb-0">{{ $order->date->format('d M Y') }} · {{ $order->party_name }} · Entered by {{ $order->creator?->name ?? '—' }}</p>
    </div>
    <div class="d-flex flex-wrap gap-2">
        @if ($order->isOpen() && $pendingTotal + $progress->sum('pending') > 0 && $user->can($config['module'].'.create'))
            <a href="{{ route('invoices.create', ['kind' => $config['invoice_kind'], 'order' => $order->id]) }}" class="btn btn-primary"><i class="bi bi-receipt me-1"></i>{{ $config['invoice_kind'] === 'sales' ? 'Make sales invoice' : 'Make purchase bill' }}</a>
        @endif
        <a href="{{ route('orders.print', $order) }}" class="btn btn-outline-secondary" target="_blank" rel="noopener"><i class="bi bi-printer me-1"></i>Print</a>
        <a href="{{ route('orders.pdf', $order) }}" class="btn btn-outline-secondary"><i class="bi bi-file-earmark-pdf me-1"></i>PDF</a>
        @if ($order->isOpen() && ! $locked && $user->can($config['module'].'.edit'))
            <a href="{{ route('orders.edit', $order) }}" class="btn btn-outline-primary"><i class="bi bi-pencil me-1"></i>Alter</a>
        @endif
        @if ($order->isOpen() && $user->can($config['module'].'.edit'))
            <button class="btn btn-outline-secondary" data-bs-toggle="collapse" data-bs-target="#close-panel" aria-expanded="false">Close order</button>
        @endif
        @if ($order->status === 'closed' && $user->can($config['module'].'.edit'))
            <form method="POST" action="{{ route('orders.reopen', $order) }}">@csrf<button class="btn btn-outline-secondary">Reopen</button></form>
        @endif
        @if ($order->isOpen() && $invoices->where(fn ($i) => $i->status() === 'posted')->isEmpty() && $user->can($config['module'].'.delete'))
            <button class="btn btn-outline-danger" data-bs-toggle="collapse" data-bs-target="#cancel-panel" aria-expanded="false">Cancel order</button>
        @endif
    </div>
</div>

@error('order')<div class="alert alert-danger">{{ $message }}</div>@enderror

@if ($order->isOpen())
    <div class="collapse" id="close-panel">
        <form method="POST" action="{{ route('orders.close', $order) }}" class="erp-card p-3 mb-3" style="max-width: 560px">
            @csrf
            <p class="small mb-2">Closing stops further invoicing; the pending quantity is dropped from the pending orders report. You can reopen it later.</p>
            <label for="close-reason" class="form-label">Reason</label>
            <input id="close-reason" name="reason" class="form-control mb-2" required maxlength="255" placeholder="E.g. customer cancelled the balance">
            <button class="btn btn-secondary">Close order</button>
        </form>
    </div>
    <div class="collapse" id="cancel-panel">
        <form method="POST" action="{{ route('orders.cancel', $order) }}" class="erp-card p-3 mb-3" style="max-width: 560px" data-confirm="Cancel order {{ $order->number }}?">
            @csrf
            <label for="cancel-reason" class="form-label">Reason for cancelling</label>
            <input id="cancel-reason" name="reason" class="form-control mb-2" required maxlength="255">
            <button class="btn btn-danger">Cancel order</button>
        </form>
    </div>
@else
    <div class="alert alert-{{ $order->status === 'cancelled' ? 'danger' : 'secondary' }} small">{{ ucfirst($order->status) }} {{ $order->status_at?->format('d M Y H:i') }}@if ($order->statusUser) by {{ $order->statusUser->name }}@endif: {{ $order->status_reason }}</div>
@endif
@if ($order->isOpen() && $locked)
    <div class="alert alert-info small">Invoices have been made against this order, so its lines are fixed. Close it if the rest will not be supplied.</div>
@endif

<div class="row g-3 mb-3">
    <div class="col-12 col-lg-4">
        <div class="erp-card p-3 h-100 small">
            <div class="text-secondary">{{ $config['party_group'] === 'sundry_debtors' ? 'Customer' : 'Supplier' }}</div>
            <div class="fw-semibold">{{ $order->party_name }}</div>
            <div style="white-space: pre-line">{{ $order->party?->partyDetails?->fullAddress() }}</div>
            @if ($order->party?->partyDetails?->gstin)<div>GSTIN: <span class="font-monospace">{{ $order->party->partyDetails->gstin }}</span></div>@endif
        </div>
    </div>
    <div class="col-12 col-lg-8">
        <div class="erp-card p-3 h-100">
            <div class="row g-2 small">
                <div class="col-6 col-md-4"><div class="text-secondary">Delivery by</div>{{ $order->delivery_date?->format('d M Y') ?? '—' }}
                    @if ($order->isOpen() && $order->delivery_date && $order->delivery_date->isPast() && $label !== 'Delivered')<span class="badge text-bg-danger">Overdue</span>@endif</div>
                <div class="col-6 col-md-4"><div class="text-secondary">Reference</div>{{ $order->reference ?? '—' }} {{ $order->reference_date?->format('d-m-Y') }}</div>
                <div class="col-6 col-md-4"><div class="text-secondary">Tax</div>{{ ['intra' => 'CGST + SGST', 'inter' => 'IGST', 'none' => 'No GST'][$order->tax_mode] }}@if ($order->place_of_supply) · {{ $order->place_of_supply }}@endif</div>
                <div class="col-6 col-md-4"><div class="text-secondary">Pending value</div>{{ $m($pendingTotal) }}</div>
            </div>
        </div>
    </div>
</div>

<div class="erp-card mb-3">
    <div class="table-responsive">
        <table class="table align-middle mb-0 table-stack">
            <thead class="table-light"><tr><th>#</th><th>Description</th><th class="text-end">Ordered</th><th class="text-end">Invoiced</th><th class="text-end">Pending</th><th class="text-end">Rate</th><th class="text-end">GST %</th><th class="text-end">Amount</th></tr></thead>
            <tbody>
            @foreach ($progress as $p)
                <tr>
                    <td data-label="#">{{ $p->item->line_no }}</td>
                    <td data-label="Description">{{ $p->item->description }}@if ($p->item->hsn_sac)<div class="small text-secondary">HSN {{ $p->item->hsn_sac }}</div>@endif</td>
                    <td data-label="Ordered" class="text-end">{{ $q($p->ordered, $p) }}</td>
                    <td data-label="Invoiced" class="text-end">{{ $q($p->delivered, $p) }}</td>
                    <td data-label="Pending" class="text-end fw-semibold">{{ $q($p->pending, $p) }}</td>
                    <td data-label="Rate" class="amount">{{ \App\Support\Money::format($p->item->rate) }}@if ((float) $p->item->discount_percent)<div class="small text-secondary">less {{ \App\Support\Decimal::trim($p->item->discount_percent) }}%</div>@endif</td>
                    <td data-label="GST %" class="text-end">{{ \App\Support\Decimal::trim($p->item->gst_rate) }}</td>
                    <td data-label="Amount" class="amount">{{ \App\Support\Money::format($p->item->total_amount) }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
</div>

<div class="row g-3">
    <div class="col-12 col-lg-7">
        <div class="erp-card mb-3">
            <div class="erp-card-header">{{ $config['invoice_kind'] === 'sales' ? 'Sales invoices' : 'Purchase bills' }} against this order</div>
            <div class="table-responsive">
                <table class="table table-sm mb-0 table-stack small">
                    <thead class="table-light"><tr><th>Number</th><th>Date</th><th>Status</th><th class="text-end">Total</th></tr></thead>
                    <tbody>
                    @forelse ($invoices as $inv)
                        <tr>
                            <td data-label="Number"><a href="{{ route('invoices.show', $inv) }}">{{ $inv->displayNumber() }}</a></td>
                            <td data-label="Date">{{ $inv->date->format('d-m-Y') }}</td>
                            <td data-label="Status">@include('accounting.vouchers._status', ['status' => $inv->status()])</td>
                            <td data-label="Total" class="amount">{{ \App\Support\Money::format($inv->grand_total) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="text-secondary text-center py-3">None yet.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        @if ($order->narration)<div class="erp-card p-3 mb-3 small"><div class="text-secondary">Narration</div>{{ $order->narration }}</div>@endif
        @if ($order->terms)<div class="erp-card p-3 mb-3 small"><div class="text-secondary">Terms</div><span style="white-space: pre-line">{{ $order->terms }}</span></div>@endif
    </div>
    <div class="col-12 col-lg-5">
        <div class="erp-card p-3">
            <table class="table table-sm mb-0">
                <tr><td>Gross</td><td class="amount">{{ \App\Support\Money::format($order->gross_total) }}</td></tr>
                @if ((float) $order->discount_total)<tr><td>Discount</td><td class="amount">-{{ \App\Support\Money::format($order->discount_total) }}</td></tr>@endif
                <tr><td>Taxable value</td><td class="amount">{{ \App\Support\Money::format($order->taxable_total) }}</td></tr>
                @if ((float) $order->cgst_total)<tr><td>CGST</td><td class="amount">{{ \App\Support\Money::format($order->cgst_total) }}</td></tr>@endif
                @if ((float) $order->sgst_total)<tr><td>SGST / UTGST</td><td class="amount">{{ \App\Support\Money::format($order->sgst_total) }}</td></tr>@endif
                @if ((float) $order->igst_total)<tr><td>IGST</td><td class="amount">{{ \App\Support\Money::format($order->igst_total) }}</td></tr>@endif
                <tr class="fw-bold fs-5"><td>Total</td><td class="amount">{{ \App\Support\Money::format($order->grand_total, true) }}</td></tr>
            </table>
        </div>
    </div>
</div>
@endsection
