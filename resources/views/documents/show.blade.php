@extends('layouts.erp')

@php
    $label = $document->typeLabel();
    $money = fn ($v) => inr((float) $v);
    $taxable = $document->lines->sum(fn ($l) => (float) $l->taxable);
    $tax = $document->lines->sum(fn ($l) => (float) $l->tax);
@endphp

@section('title', $label.' #'.$document->number)

@section('content')
<div class="container-fluid py-3">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4 d-print-none">
        <h2 class="h4 mb-0">
            {{ $label }} #{{ $document->number }}
            <span class="badge {{ ['open' => 'text-bg-primary', 'converted' => 'text-bg-success', 'cancelled' => 'text-bg-secondary'][$document->status] }} align-middle">
                {{ \App\Models\BusinessDocument::STATUSES[$document->status] }}
            </span>
        </h2>
        <div class="d-flex flex-wrap gap-2">
            <button type="button" class="btn btn-outline-secondary" onclick="window.print()">Print</button>
            @if ($document->status === 'open')
                @if ($document->convertsTo())
                    <form method="POST" action="{{ route('companies.documents.convert', [$company, $type, $document]) }}">
                        @csrf
                        <button class="btn btn-primary">Convert to {{ \App\Models\Voucher::TYPES[$document->convertsTo()] }} Invoice</button>
                    </form>
                @endif
                <a href="{{ route('companies.documents.edit', [$company, $type, $document]) }}" class="btn btn-outline-primary">Edit</a>
                <form method="POST" action="{{ route('companies.documents.destroy', [$company, $type, $document]) }}"
                    onsubmit="return confirm('Cancel this {{ strtolower($label) }}?');">
                    @csrf
                    @method('DELETE')
                    <button class="btn btn-outline-danger">Cancel</button>
                </form>
            @elseif ($document->status === 'converted' && $document->voucher)
                <a href="{{ route('companies.gst-invoices.show', [$company, $document->voucher]) }}" class="btn btn-outline-primary">
                    View {{ $document->voucher->typeLabel() }} #{{ $document->voucher->number }}
                </a>
            @elseif ($document->status === 'cancelled')
                <form method="POST" action="{{ route('companies.documents.destroy', [$company, $type, $document]) }}"
                    onsubmit="return confirm('Delete this cancelled {{ strtolower($label) }}?');">
                    @csrf
                    @method('DELETE')
                    <button class="btn btn-outline-danger">Delete</button>
                </form>
            @endif
            <a href="{{ route('companies.documents.index', [$company, $type]) }}" class="btn btn-outline-secondary">Back</a>
        </div>
    </div>

    @if (session('success'))
        <div class="alert alert-success d-print-none">{{ session('success') }}</div>
    @endif
    @if (session('error'))
        <div class="alert alert-danger d-print-none">{{ session('error') }}</div>
    @endif

    <div class="card border-0 shadow-sm">
        <div class="card-body p-3 p-md-4">
            <div class="text-center mb-3"><div class="h5 mb-0">{{ strtoupper($label) }}</div></div>

            <div class="row g-3 mb-3">
                <div class="col-12 col-md-6">
                    <div class="text-secondary small">{{ $document->side() === 'purchase' ? 'Buyer' : 'From' }}</div>
                    <div class="fw-semibold">{{ $company->legal_name ?: $company->name }}</div>
                    @if ($company->address || $company->city)
                        <div class="small">{{ collect([$company->address, $company->city, $company->pincode])->filter()->join(', ') }}</div>
                    @endif
                    @if ($company->gstin)<div class="small">GSTIN: {{ $company->gstin }}</div>@endif
                </div>
                <div class="col-12 col-md-6">
                    <div class="text-secondary small">{{ $document->side() === 'purchase' ? 'Supplier' : 'To' }}</div>
                    <div class="fw-semibold">{{ $document->party->name }}</div>
                    @if ($document->party->address || $document->party->city)
                        <div class="small">{{ collect([$document->party->address, $document->party->city, $document->party->pincode])->filter()->join(', ') }}</div>
                    @endif
                    @if ($document->party->gstin)<div class="small">GSTIN: {{ $document->party->gstin }}</div>@endif
                    <div class="small">No. {{ $document->numberLabel() }} · {{ $document->date->format('d-m-Y') }}</div>
                    @if ($document->valid_until)<div class="small">Valid until {{ $document->valid_until->format('d-m-Y') }}</div>@endif
                    @if ($document->reference)<div class="small">Ref: {{ $document->reference }}</div>@endif
                </div>
            </div>

            <div class="table-responsive">
                <table class="table table-bordered align-middle mb-3">
                    <thead class="table-light">
                        <tr><th>#</th><th>Description</th><th>HSN/SAC</th><th class="text-end">Qty</th><th class="text-end">Rate</th><th class="text-end">Taxable</th><th class="text-end">GST</th><th class="text-end">Total</th></tr>
                    </thead>
                    <tbody>
                        @foreach ($document->lines as $i => $line)
                            <tr>
                                <td>{{ $i + 1 }}</td>
                                <td>
                                    {{ $line->description ?: ($line->stockItem?->name ?? $line->account->name) }}
                                    @if ((float) $line->discount_percent > 0)
                                        <div class="small text-secondary">{{ inr($line->gross_amount) }} less {{ (float) $line->discount_percent }}% discount</div>
                                    @endif
                                </td>
                                <td>{{ $line->hsn_sac }}</td>
                                <td class="text-end">{{ $line->quantity !== null ? rtrim(rtrim(number_format((float) $line->quantity, 3), '0'), '.').' '.$line->stockItem?->unit?->symbol : '' }}</td>
                                <td class="text-end">{{ $line->item_rate !== null ? $money($line->item_rate) : '' }}</td>
                                <td class="text-end">{{ $money($line->taxable) }}</td>
                                <td class="text-end">{{ $money($line->tax) }} <span class="small text-secondary">({{ (float) $line->rate }}%)</span></td>
                                <td class="text-end">{{ $money((float) $line->taxable + (float) $line->tax) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot class="fw-semibold">
                        <tr><td colspan="5">Total</td><td class="text-end">{{ $money($taxable) }}</td><td class="text-end">{{ $money($tax) }}</td><td class="text-end">{{ $money($document->amount) }}</td></tr>
                    </tfoot>
                </table>
            </div>

            @if ($document->notes)
                <p class="small mb-0"><span class="text-secondary">Notes:</span> {{ $document->notes }}</p>
            @endif
            @if ($document->type === 'delivery_challan')
                <div class="row mt-5 small">
                    <div class="col-6">Received the above goods in good condition.<br><br>Receiver's signature</div>
                    <div class="col-6 text-end">For {{ $company->legal_name ?: $company->name }}<br><br>Authorised signatory</div>
                </div>
            @endif
        </div>
    </div>
</div>
@endsection
