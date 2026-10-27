@extends('layouts.erp')

@php($posted = $invoice->status() === \App\Models\Voucher::POSTED)
@php($partyWord = $config['party_group'] === 'sundry_debtors' ? 'Customer' : 'Supplier')

@section('title', ($invoice->exists ? 'Alter ' : 'New ').$config['label'])

@section('content')
<div class="page-header">
    <div>
        <h1>{{ $invoice->exists ? 'Alter '.$config['label'].' '.$invoice->displayNumber() : 'New '.mb_strtolower($config['label']) }}</h1>
        <p class="text-secondary mb-0">Amounts and GST are recalculated on saving; the preview below is for guidance.</p>
    </div>
    <a href="{{ $invoice->exists ? route('invoices.show', $invoice) : route('invoices.index', ['kind' => $kind]) }}" class="btn btn-outline-secondary">Back</a>
</div>

@if ($posted)
    <div class="alert alert-warning small"><i class="bi bi-clock-history me-1"></i>This document is posted. Saving creates a new revision of its voucher; the previous version is kept in the voucher history.</div>
@endif

<form method="POST" action="{{ $invoice->exists ? route('invoices.update', $invoice) : route('invoices.store') }}" id="invoice-form" novalidate>
    @csrf
    @if ($invoice->exists) @method('PUT') @endif
    <input type="hidden" name="kind" value="{{ $kind }}">

    <div class="erp-card p-3 mb-3">
        <div class="row g-3">
            <div class="col-12 col-md-6 col-lg-4">
                <label for="party_account_id" class="form-label">{{ $partyWord }} <span class="text-danger">*</span></label>
                <select id="party_account_id" name="party_account_id" class="form-select @error('party_account_id') is-invalid @enderror" required>
                    <option value="">Select</option>
                    @foreach ($parties as $p)
                        <option value="{{ $p['id'] }}" @selected((int) ($input['party_account_id'] ?? 0) === $p['id'])>{{ $p['name'] }}{{ in_array($p['type'], ['cash', 'bank'], true) ? ' (cash / bank)' : '' }}</option>
                    @endforeach
                </select>
                @error('party_account_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                <div class="small text-secondary mt-1" id="party-info"></div>
                @can($config['module'].'.create')
                    <a class="small" href="{{ route($config['party_group'] === 'sundry_debtors' ? 'customers.create' : 'suppliers.create') }}" target="_blank" rel="noopener">+ New {{ mb_strtolower($partyWord) }}</a>
                @endcan
            </div>
            <div class="col-6 col-md-3 col-lg-2">
                <label for="date" class="form-label">Date</label>
                <input id="date" type="date" name="date" value="{{ $input['date'] ?? '' }}" class="form-control @error('date') is-invalid @enderror" required>
                @error('date')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="col-6 col-md-3 col-lg-2">
                <label for="voucher_type_id" class="form-label">Voucher type</label>
                <select id="voucher_type_id" name="voucher_type_id" class="form-select" @disabled($posted)>
                    @foreach ($types as $t)
                        <option value="{{ $t->id }}" data-manual="{{ $t->isAutoNumbered() ? 0 : 1 }}" @selected((int) ($input['voucher_type_id'] ?? 0) === $t->id)>{{ $t->name }}</option>
                    @endforeach
                </select>
                @if ($posted)<input type="hidden" name="voucher_type_id" value="{{ $input['voucher_type_id'] }}">@endif
            </div>
            <div class="col-6 col-md-3 col-lg-2">
                <label for="voucher_number" class="form-label">Number</label>
                <input id="voucher_number" name="voucher_number" value="{{ $input['voucher_number'] ?? '' }}" class="form-control @error('voucher_number') is-invalid @enderror" maxlength="50" placeholder="Automatic">
                @error('voucher_number')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="col-6 col-md-3 col-lg-2">
                <label for="due_date" class="form-label">Due date</label>
                <input id="due_date" type="date" name="due_date" value="{{ $input['due_date'] ?? '' }}" class="form-control @error('due_date') is-invalid @enderror">
                @error('due_date')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="col-6 col-md-3 col-lg-3">
                <label for="reference" class="form-label">{{ $config['tax'] === 'input' ? 'Supplier invoice no.' : 'Order / reference no.' }}</label>
                <input id="reference" name="reference" value="{{ $input['reference'] ?? '' }}" class="form-control @error('reference') is-invalid @enderror" maxlength="50">
                @error('reference')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="col-6 col-md-3 col-lg-2">
                <label for="reference_date" class="form-label">Reference date</label>
                <input id="reference_date" type="date" name="reference_date" value="{{ $input['reference_date'] ?? '' }}" class="form-control">
            </div>
            <div class="col-12 col-md-6 col-lg-3">
                <label for="place_of_supply" class="form-label">{{ $config['tax'] === 'input' ? 'Supplier state' : 'Place of supply' }}</label>
                <select id="place_of_supply" name="place_of_supply" class="form-select @error('place_of_supply') is-invalid @enderror">
                    <option value="">From {{ mb_strtolower($partyWord) }}</option>
                    @foreach ($states as $s)<option value="{{ $s }}" @selected(($input['place_of_supply'] ?? '') === $s)>{{ $s }}</option>@endforeach
                </select>
                <div class="small text-secondary mt-1" id="tax-mode"></div>
            </div>
            @if ($invoice->isReturn())
                <div class="col-12 col-md-6 col-lg-4">
                    <label for="original_invoice_id" class="form-label">Against {{ $kind === 'credit_note' ? 'invoice' : 'bill' }}</label>
                    <select id="original_invoice_id" name="original_invoice_id" class="form-select @error('original_invoice_id') is-invalid @enderror">
                        <option value="">None (keep as a separate credit)</option>
                        @foreach ($originals as $o)
                            <option value="{{ $o['id'] }}" data-party="{{ $o['party'] }}" @selected((int) ($input['original_invoice_id'] ?? 0) === $o['id'])>{{ $o['label'] }}</option>
                        @endforeach
                    </select>
                    @error('original_invoice_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
            @endif
        </div>
        <details class="mt-3" @if (! empty($input['billing_address']) || ! empty($input['shipping_address'])) open @endif>
            <summary class="small fw-semibold">Billing and shipping address</summary>
            <div class="row g-3 mt-0">
                <div class="col-12 col-md-6">
                    <label for="billing_address" class="form-label small">Billing address</label>
                    <textarea id="billing_address" name="billing_address" rows="3" class="form-control form-control-sm" maxlength="1000">{{ $input['billing_address'] ?? '' }}</textarea>
                </div>
                <div class="col-12 col-md-6">
                    <label for="shipping_address" class="form-label small">Shipping address</label>
                    <textarea id="shipping_address" name="shipping_address" rows="3" class="form-control form-control-sm" maxlength="1000">{{ $input['shipping_address'] ?? '' }}</textarea>
                </div>
            </div>
        </details>
    </div>

    <div class="erp-card mb-3">
        <div class="erp-card-header d-flex justify-content-between align-items-center">
            <span>Items</span>
            <button type="button" class="btn btn-sm btn-outline-primary" id="add-item" data-shortcut="a"><i class="bi bi-plus-lg"></i> Add item</button>
        </div>
        @error('items')<div class="alert alert-danger m-3 mb-0 small">{{ $message }}</div>@enderror
        @error('invoice')<div class="alert alert-danger m-3 mb-0 small">{{ $message }}</div>@enderror
        <div id="invoice-items" class="voucher-lines p-2 p-md-3">
            @php($rows = $input['items'] ?? [])
            @if ($rows === [])@php($rows = [[]])@endif
            @foreach (array_values($rows) as $i => $item)
                @include('billing.invoices._item', ['i' => $i, 'item' => $item])
            @endforeach
        </div>
        <div class="border-top p-3">
            <div class="row justify-content-end">
                <div class="col-12 col-md-6 col-lg-4">
                    <table class="table table-sm mb-0 small" id="totals">
                        <tr><td>Gross</td><td class="amount" data-total="gross">0.00</td></tr>
                        <tr><td>Discount</td><td class="amount" data-total="discount">0.00</td></tr>
                        <tr><td>Taxable value</td><td class="amount" data-total="taxable">0.00</td></tr>
                        <tr class="tax-row" data-tax="cgst"><td>CGST</td><td class="amount" data-total="cgst">0.00</td></tr>
                        <tr class="tax-row" data-tax="sgst"><td>SGST / UTGST</td><td class="amount" data-total="sgst">0.00</td></tr>
                        <tr class="tax-row" data-tax="igst"><td>IGST</td><td class="amount" data-total="igst">0.00</td></tr>
                        <tr><td>Round off</td><td class="amount" data-total="round">0.00</td></tr>
                        <tr class="fw-bold fs-6"><td>Total</td><td class="amount" data-total="grand">0.00</td></tr>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <div class="erp-card p-3 mb-3">
        <div class="row g-3">
            <div class="col-12 col-md-6">
                <label for="narration" class="form-label">Narration</label>
                <textarea id="narration" name="narration" rows="2" class="form-control" maxlength="2000">{{ $input['narration'] ?? '' }}</textarea>
            </div>
            <div class="col-12 col-md-6">
                <label for="terms" class="form-label">Terms &amp; conditions</label>
                <textarea id="terms" name="terms" rows="2" class="form-control" maxlength="2000">{{ $input['terms'] ?? '' }}</textarea>
            </div>
            @if ($posted)
                <div class="col-12">
                    <label for="alteration_reason" class="form-label">Reason for alteration <span class="text-danger">*</span></label>
                    <input id="alteration_reason" name="alteration_reason" class="form-control @error('alteration_reason') is-invalid @enderror" maxlength="255" value="{{ old('alteration_reason') }}" required>
                    @error('alteration_reason')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
            @endif
        </div>
    </div>

    <div class="d-flex flex-wrap gap-2 mb-5">
        @if ($posted)
            <button type="submit" name="action" value="post" class="btn btn-primary" data-shortcut="s">Save alteration</button>
        @else
            <button type="submit" name="action" value="post" class="btn btn-primary" data-shortcut="s">
                @if ($company->setting('require_voucher_approval') && auth()->user()->cannot('vouchers.approve'))
                    Submit for approval
                @elseif (auth()->user()->can('vouchers.post'))
                    Post {{ mb_strtolower($config['label']) }}
                @else
                    Save
                @endif
            </button>
            <button type="submit" name="action" value="draft" class="btn btn-outline-secondary">Save as draft</button>
        @endif
    </div>
</form>

<template id="item-template">
    @include('billing.invoices._item', ['i' => '__I__', 'item' => []])
</template>
@endsection

@push('scripts')
<script>
window.invoiceForm = {
    parties: @json($parties),
    stockItems: @json($stockItems),
    batchOptions: @json((object) $batchOptions),
    companyState: @json($company->state),
    gstEnabled: @json($gstEnabled),
    purchase: @json($config['tax'] === 'input'),
    roundOff: @json((bool) $company->setting('invoice_round_off')),
};
</script>
<script src="{{ asset('js/invoice-form.js') }}"></script>
@endpush
