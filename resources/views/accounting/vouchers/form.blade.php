@extends('layouts.erp')

@section('title', ($voucher->exists ? 'Alter ' : 'New ').$type->name)

@section('content')
<div class="page-header">
    <div>
        <h1>{{ $voucher->exists ? 'Alter '.$type->name.' '.$voucher->displayNumber() : $type->name.' voucher' }}</h1>
        <p class="text-secondary mb-0">{{ $type->baseLabel() }} · Total debits must equal total credits.</p>
    </div>
    <a href="{{ $voucher->exists ? route('vouchers.show', $voucher) : route('vouchers.index') }}" class="btn btn-outline-secondary">Back</a>
</div>

@if ($voucher->exists && $voucher->isPosted())
    <div class="alert alert-warning small"><i class="bi bi-clock-history me-1"></i>This voucher is posted. Your changes are saved as a new revision and the previous version is kept in its history.</div>
@endif

<form method="POST" action="{{ $voucher->exists ? route('vouchers.update', $voucher) : route('vouchers.store') }}" id="voucher-form" novalidate>
    @csrf
    @if ($voucher->exists) @method('PUT') @endif

    <div class="erp-card p-3 mb-3">
        <div class="row g-3">
            <div class="col-12 col-sm-6 col-lg-3">
                <label for="voucher_type_id" class="form-label">Voucher type</label>
                @if ($voucher->exists && $voucher->isPosted())
                    <input class="form-control" value="{{ $type->name }}" disabled>
                    <input type="hidden" name="voucher_type_id" value="{{ $type->id }}">
                @else
                    <select id="voucher_type_id" name="voucher_type_id" class="form-select" onchange="if (!{{ $voucher->exists ? 'true' : 'false' }}) window.location='{{ route('vouchers.create') }}?type='+this.value">
                        @foreach ($types as $t)
                            <option value="{{ $t->id }}" @selected((int) old('voucher_type_id', $type->id) === $t->id)>{{ $t->name }}</option>
                        @endforeach
                    </select>
                @endif
            </div>
            <div class="col-6 col-sm-6 col-lg-2">
                <label for="date" class="form-label">Date</label>
                <input id="date" type="date" name="date" value="{{ old('date', $voucher->date?->toDateString() ?? $voucher->date) }}" class="form-control @error('date') is-invalid @enderror" required>
                @error('date')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="col-6 col-sm-6 col-lg-2">
                <label for="voucher_number" class="form-label">Number</label>
                @if ($type->isAutoNumbered())
                    <input class="form-control" value="{{ $voucher->voucher_number ?? 'Automatic' }}" disabled>
                @else
                    <input id="voucher_number" name="voucher_number" value="{{ old('voucher_number', $voucher->voucher_number) }}" class="form-control @error('voucher_number') is-invalid @enderror" maxlength="50">
                    @error('voucher_number')<div class="invalid-feedback">{{ $message }}</div>@enderror
                @endif
            </div>
            <div class="col-6 col-sm-6 col-lg-3">
                <label for="reference" class="form-label">Reference no.</label>
                <input id="reference" name="reference" value="{{ old('reference', $voucher->reference) }}" class="form-control" maxlength="100" placeholder="Cheque / bill / UTR">
            </div>
            <div class="col-6 col-sm-6 col-lg-2">
                <label for="reference_date" class="form-label">Reference date</label>
                <input id="reference_date" type="date" name="reference_date" value="{{ old('reference_date', $voucher->reference_date?->toDateString()) }}" class="form-control">
            </div>
        </div>
    </div>

    @php($instType = old('instrument_type', $voucher->instrument_type ?? (in_array($type->base_type, ['payment', 'receipt', 'contra'], true) ? '' : null)))
    <details class="erp-card mb-3" @if ($instType !== null || $errors->hasAny(['instrument_type', 'instrument_number', 'instrument_date', 'payee_name'])) open @endif>
        <summary class="erp-card-header">Cheque / bank transfer details <span class="text-secondary small fw-normal">(optional, for the cheque register)</span></summary>
        <div class="p-3">
            <div class="row g-3">
                <div class="col-6 col-md-3 col-lg-2">
                    <label for="instrument_type" class="form-label">Instrument</label>
                    <select id="instrument_type" name="instrument_type" class="form-select @error('instrument_type') is-invalid @enderror">
                        <option value="">None</option>
                        @foreach (\App\Models\Voucher::INSTRUMENTS as $k => $label)<option value="{{ $k }}" @selected($instType === $k)>{{ $label }}</option>@endforeach
                    </select>
                    @error('instrument_type')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-6 col-md-3 col-lg-2">
                    <label for="instrument_number" class="form-label">Cheque / UTR no.</label>
                    <input id="instrument_number" name="instrument_number" value="{{ old('instrument_number', $voucher->instrument_number) }}" class="form-control @error('instrument_number') is-invalid @enderror" maxlength="30" autocomplete="off">
                    @error('instrument_number')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    <div class="form-text" id="cheque-next"></div>
                </div>
                <div class="col-6 col-md-3 col-lg-2">
                    <label for="instrument_date" class="form-label">Instrument date</label>
                    <input id="instrument_date" type="date" name="instrument_date" value="{{ old('instrument_date', $voucher->instrument_date?->toDateString()) }}" class="form-control @error('instrument_date') is-invalid @enderror">
                    <div class="form-text">Blank = voucher date</div>
                </div>
                <div class="col-12 col-md-6 col-lg-4">
                    <label for="payee_name" class="form-label">Pay to / received from (name on cheque)</label>
                    <input id="payee_name" name="payee_name" value="{{ old('payee_name', $voucher->payee_name) }}" class="form-control" maxlength="150" placeholder="Blank = the party ledger's name">
                </div>
            </div>
        </div>
    </details>

    <div class="erp-card mb-3">
        <div class="erp-card-header d-flex justify-content-between align-items-center">
            <span>Particulars</span>
            <button type="button" class="btn btn-sm btn-outline-primary" id="add-line" data-shortcut="a"><i class="bi bi-plus-lg"></i> Add line</button>
        </div>
        <div class="d-none d-md-flex px-3 pt-2 small text-secondary fw-semibold">
            <div style="width: 80px">Dr/Cr</div>
            <div class="flex-grow-1">Ledger</div>
            <div style="width: 170px" class="text-end">Amount</div>
            <div style="width: 48px"></div>
        </div>
        <div id="voucher-lines" class="voucher-lines p-2 p-md-3 pt-md-1"></div>
        <div class="border-top px-3 py-2 d-flex flex-wrap justify-content-end gap-4 small">
            <div>Total Dr: <strong id="total-dr" class="amount">0.00</strong></div>
            <div>Total Cr: <strong id="total-cr" class="amount">0.00</strong></div>
            <div id="difference-wrap" class="text-danger">Difference: <strong id="difference" class="amount">0.00</strong></div>
        </div>
    </div>

    <div class="erp-card p-3 mb-3">
        <label for="narration" class="form-label">Narration</label>
        <textarea id="narration" name="narration" rows="2" class="form-control" maxlength="2000">{{ old('narration', $voucher->narration ?? $type->default_narration) }}</textarea>
        @if ($voucher->exists && $voucher->isPosted())
            <label for="alteration_reason" class="form-label mt-3">Reason for alteration</label>
            <input id="alteration_reason" name="alteration_reason" class="form-control" maxlength="255" value="{{ old('alteration_reason') }}" placeholder="Shown in the voucher history">
        @endif
    </div>

    <div class="d-flex flex-wrap gap-2 mb-5">
        @if ($voucher->exists && $voucher->isPosted())
            <button type="submit" name="action" value="post" class="btn btn-primary" data-shortcut="s">Save alteration</button>
        @else
            <button type="submit" name="action" value="post" class="btn btn-primary" data-shortcut="s">
                @if (app(\App\Support\CurrentCompany::class)->get()->setting('require_voucher_approval') && auth()->user()->cannot('vouchers.approve'))
                    Submit for approval
                @elseif (auth()->user()->can('vouchers.post'))
                    Post voucher
                @else
                    Save
                @endif
            </button>
            <button type="submit" name="action" value="draft" class="btn btn-outline-secondary">Save as draft</button>
            @unless ($voucher->exists)
                <button type="submit" name="action" value="post" class="btn btn-outline-primary" onclick="document.getElementById('add_another').value=1">Post &amp; add another</button>
                <input type="hidden" name="add_another" id="add_another" value="0">
            @endunless
        @endif
    </div>
</form>

<template id="line-template">
    <div class="voucher-line row g-2 align-items-start mb-md-2">
        <div class="col-4 col-md-auto" style="min-width: 80px">
            <label class="form-label small d-md-none">Dr/Cr</label>
            <select class="form-select line-side" aria-label="Debit or credit">
                <option value="Dr">Dr</option>
                <option value="Cr">Cr</option>
            </select>
        </div>
        <div class="col-8 col-md">
            <label class="form-label small d-md-none">Ledger</label>
            <select class="form-select line-account" aria-label="Ledger"></select>
            <div class="line-balance small text-secondary mt-1"></div>
        </div>
        <div class="col-8 col-md-auto" style="width: 170px">
            <label class="form-label small d-md-none">Amount</label>
            <input type="text" inputmode="decimal" class="form-control text-end line-amount" aria-label="Amount" autocomplete="off">
        </div>
        <div class="col-4 col-md-auto d-flex align-items-end align-items-md-start justify-content-end" style="min-width: 48px">
            <button type="button" class="btn btn-outline-danger line-remove" aria-label="Remove line"><i class="bi bi-x-lg"></i></button>
        </div>
        <div class="col-12 line-extra">
            <div class="row g-2">
                <div class="col-12 col-md-6 line-cc-wrap">
                    <select class="form-select form-select-sm line-cc" aria-label="Cost centre"></select>
                </div>
                <div class="col-12 col-md-6">
                    <input type="text" class="form-control form-control-sm line-narration" placeholder="Line narration (optional)" maxlength="500" aria-label="Line narration">
                </div>
            </div>
        </div>
        <div class="col-12 line-bills" hidden>
            <div class="border rounded p-2 bg-light">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <span class="small fw-semibold">Bill-wise details</span>
                    <button type="button" class="btn btn-sm btn-link add-bill">+ Add bill</button>
                </div>
                <div class="bill-rows"></div>
                <div class="small bill-status"></div>
            </div>
        </div>
    </div>
</template>

<template id="bill-template">
    <div class="row g-1 mb-1 bill-row">
        <div class="col-6 col-md-3">
            <select class="form-select form-select-sm bill-type" aria-label="Type of reference">
                @foreach ($billTypes as $k => $label)<option value="{{ $k }}">{{ $label }}</option>@endforeach
            </select>
        </div>
        <div class="col-6 col-md-3"><input class="form-control form-control-sm bill-ref" placeholder="Bill ref" aria-label="Bill reference" maxlength="100"></div>
        <div class="col-5 col-md-2"><input type="date" class="form-control form-control-sm bill-due" aria-label="Due date"></div>
        <div class="col-5 col-md-3"><input class="form-control form-control-sm text-end bill-amount" inputmode="decimal" placeholder="Amount" aria-label="Bill amount"></div>
        <div class="col-2 col-md-1 text-end"><button type="button" class="btn btn-sm btn-outline-danger bill-remove" aria-label="Remove bill"><i class="bi bi-x"></i></button></div>
    </div>
</template>
@endsection

@push('scripts')
<script>
window.voucherForm = {
    baseType: @json($type->base_type),
    ledgers: @json($ledgers),
    costCentres: @json($costCentres),
    lines: @json($lines),
    openBillsUrl: @json(url('/ledgers/__ID__/open-bills')),
    excludeVoucher: @json($voucher->id),
};
</script>
<script src="{{ asset('js/voucher-form.js') }}"></script>
<script>
// Suggest the next unused cheque leaf of the paying bank.
(function () {
    var next = @json($chequeNext ?? []);
    var type = document.getElementById('instrument_type'), number = document.getElementById('instrument_number'), hint = document.getElementById('cheque-next');
    if (!type) return;
    function bank() {
        var found = null;
        document.querySelectorAll('#voucher-lines .voucher-line').forEach(function (row) {
            var acc = row.querySelector('.line-account'), side = row.querySelector('.line-side');
            if (acc && next[acc.value] && (!found || side.value === 'Cr')) found = acc.value;
        });
        return found;
    }
    function update() {
        var b = type.value === 'cheque' ? bank() : null;
        hint.textContent = b ? 'Next unused leaf: ' + next[b] : '';
        if (b && !number.value) number.value = next[b];
    }
    type.addEventListener('change', update);
    document.getElementById('voucher-lines').addEventListener('change', update);
    update();
})();
</script>
@endpush
