@extends('layouts.erp')

@section('title', $voucher->typeLabel().' #'.$voucher->number)

@section('content')
@php
    $lines = $voucher->gstLines;
    $taxable = $lines->sum(fn ($l) => (float) $l->taxable);
    $cgst = $lines->sum(fn ($l) => (float) $l->cgst);
    $sgst = $lines->sum(fn ($l) => (float) $l->sgst);
    $igst = $lines->sum(fn ($l) => (float) $l->igst);
    $money = fn ($v) => inr((float) $v, 2);
@endphp
<div class="container-fluid py-3">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4 d-print-none">
        <h2 class="h4 mb-0">{{ $voucher->typeLabel() }} #{{ $voucher->number }}</h2>
        <div class="d-flex flex-wrap gap-2">
            <button type="button" class="btn btn-outline-secondary" onclick="window.print()">Print</button>
            <a href="{{ route('companies.vouchers.show', [$company, $voucher]) }}" class="btn btn-outline-primary">Accounting entries</a>
            <a href="{{ route('companies.vouchers.index', $company) }}" class="btn btn-outline-secondary">Back to Day Book</a>
        </div>
    </div>

    @if (session('success'))
        <div class="alert alert-success d-print-none">{{ session('success') }}</div>
    @endif

    <div class="card border-0 shadow-sm">
        <div class="card-body p-3 p-md-4">
            <div class="text-center mb-3">
                <div class="h5 mb-0">{{ ['sales' => 'TAX INVOICE', 'purchase' => 'PURCHASE INVOICE', 'credit_note' => 'CREDIT NOTE', 'debit_note' => 'DEBIT NOTE'][$voucher->type] ?? 'INVOICE' }}</div>
            </div>

            <div class="row g-3 mb-3">
                <div class="col-12 col-md-6">
                    <div class="text-secondary small">{{ in_array($voucher->type, ['sales', 'credit_note']) ? 'From' : 'Bill to' }}</div>
                    <div class="fw-semibold">{{ $company->legal_name ?: $company->name }}</div>
                    @if ($company->address)<div class="small">{{ $company->address }}</div>@endif
                    @if ($company->gstin)<div class="small">GSTIN: {{ $company->gstin }}</div>@endif
                    <div class="small">State: {{ \App\Support\GstStates::name($company->stateCode()) }} ({{ $company->stateCode() }})</div>
                </div>
                <div class="col-12 col-md-6">
                    <div class="text-secondary small">{{ in_array($voucher->type, ['sales', 'credit_note']) ? 'Bill to' : 'Supplier' }}</div>
                    <div class="fw-semibold">{{ $voucher->party?->name }}</div>
                    @if ($voucher->party?->gstin)<div class="small">GSTIN: {{ $voucher->party->gstin }}</div>@endif
                    <div class="small">Place of supply: {{ \App\Support\GstStates::name($voucher->place_of_supply) }} ({{ $voucher->place_of_supply }})</div>
                    <div class="small">Invoice no. {{ $voucher->number }} · {{ $voucher->date->format('d-m-Y') }}
                        @if ($voucher->reference) · Ref {{ $voucher->reference }} @endif
                    </div>
                </div>
            </div>

            <div class="table-responsive">
                <table class="table table-bordered align-middle mb-3">
                    <thead class="table-light">
                        <tr>
                            <th>#</th>
                            <th>Description</th>
                            <th>HSN/SAC</th>
                            <th class="text-end">Taxable</th>
                            <th class="text-end">Rate</th>
                            @if ($igst > 0)
                                <th class="text-end">IGST</th>
                            @else
                                <th class="text-end">CGST</th>
                                <th class="text-end">SGST</th>
                            @endif
                            <th class="text-end">Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($lines as $i => $line)
                            <tr>
                                <td>{{ $i + 1 }}</td>
                                <td>
                                    {{ $line->description ?: ($line->stockItem?->name ?? $line->account->name) }}
                                    @if ($line->stockItem)
                                        <div class="small text-secondary">
                                            {{ rtrim(rtrim(inr((float) $line->quantity, 3), '0'), '.') }} {{ $line->stockItem->unit->symbol }}
                                            × {{ $money($line->item_rate) }}
                                        </div>
                                    @endif
                                </td>
                                <td>{{ $line->hsn_sac }}</td>
                                <td class="text-end">{{ $money($line->taxable) }}</td>
                                <td class="text-end">{{ (float) $line->rate }}%</td>
                                @if ($igst > 0)
                                    <td class="text-end">{{ $money($line->igst) }}</td>
                                @else
                                    <td class="text-end">{{ $money($line->cgst) }}</td>
                                    <td class="text-end">{{ $money($line->sgst) }}</td>
                                @endif
                                <td class="text-end">{{ $money((float) $line->taxable + $line->tax()) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot class="fw-semibold">
                        <tr>
                            <td colspan="3">Total</td>
                            <td class="text-end">{{ $money($taxable) }}</td>
                            <td></td>
                            @if ($igst > 0)
                                <td class="text-end">{{ $money($igst) }}</td>
                            @else
                                <td class="text-end">{{ $money($cgst) }}</td>
                                <td class="text-end">{{ $money($sgst) }}</td>
                            @endif
                            <td class="text-end">{{ $money($voucher->amount) }}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            @if ($voucher->narration)
                <p class="small mb-0"><span class="text-secondary">Narration:</span> {{ $voucher->narration }}</p>
            @endif
        </div>
    </div>
</div>
@endsection
