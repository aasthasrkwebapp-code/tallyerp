@extends('layouts.erp')

@section('title', $voucher->typeLabel().' #'.$voucher->number)

@section('content')
<div class="container-fluid py-3">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4 d-print-none">
        <h2 class="h4 mb-0">{{ $voucher->typeLabel() }} #{{ $voucher->number }}</h2>
        <div class="d-flex flex-wrap gap-2">
            <a href="{{ \App\Support\WhatsApp::link($voucher->party?->phone, \App\Support\WhatsApp::invoiceMessage($voucher)) }}"
                target="_blank" rel="noopener" class="btn btn-success">
                <i class="bi bi-whatsapp"></i> Send on WhatsApp
            </a>
            <button type="button" class="btn btn-outline-secondary" data-link="{{ \App\Support\WhatsApp::invoiceUrl($voucher) }}"
                onclick="navigator.clipboard.writeText(this.dataset.link).then(() => this.textContent = 'Link copied')">Copy link</button>
            <button type="button" class="btn btn-outline-secondary" onclick="window.print()">Print</button>
            <button type="button" class="btn btn-outline-secondary" onclick="window.print()"
                title="In the print window, choose &quot;Save as PDF&quot; as the printer">Download PDF</button>
            <div class="btn-group">
                <a href="{{ route('companies.gst-invoices.receipt', [$company, $voucher, 'width' => 80]) }}" target="_blank" class="btn btn-outline-secondary">Thermal 80mm</a>
                <a href="{{ route('companies.gst-invoices.receipt', [$company, $voucher, 'width' => 58]) }}" target="_blank" class="btn btn-outline-secondary">58mm</a>
            </div>
            @if (in_array($voucher->type, array_keys(\App\Support\EInvoice::DOC_TYPES)))
                <a href="{{ route('companies.gst-invoices.e-invoice', [$company, $voucher]) }}" class="btn btn-outline-primary">e-Invoice JSON</a>
            @endif
            <a href="{{ route('companies.vouchers.show', [$company, $voucher]) }}" class="btn btn-outline-primary">Accounting entries</a>
            <a href="{{ route('companies.vouchers.index', $company) }}" class="btn btn-outline-secondary">Back to Day Book</a>
        </div>
    </div>

    @if (session('success'))
        <div class="alert alert-success d-print-none">{{ session('success') }}</div>
    @endif
    @if ($errors->any())
        <div class="alert alert-danger d-print-none">
            <ul class="mb-0">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif
    @if (session('ewaybill_problems'))
        <div class="alert alert-warning d-print-none">
            <div class="fw-semibold mb-1">Fix these before generating the e-way bill:</div>
            <ul class="mb-0">
                @foreach (session('ewaybill_problems') as $problem)
                    <li>{{ $problem }}</li>
                @endforeach
            </ul>
        </div>
    @endif
    @if (session('einvoice_problems'))
        <div class="alert alert-warning d-print-none">
            <div class="fw-semibold mb-1">Fix these before generating the e-invoice:</div>
            <ul class="mb-0">
                @foreach (session('einvoice_problems') as $problem)
                    <li>{{ $problem }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @include('gst-invoices._sheet')

    @php $ewbNeeded = \App\Support\EWayBill::hasGoods($voucher) && (float) $voucher->amount > \App\Support\EWayBill::THRESHOLD; @endphp
    @if (\App\Support\EWayBill::hasGoods($voucher))
    <details class="card border-0 shadow-sm mt-3 d-print-none" @if (session('ewaybill_problems') || $ewbNeeded) open @endif>
        <summary class="card-header bg-white fw-semibold">
            e-Way Bill
            @if ($voucher->eway_bill_no)
                <span class="badge text-bg-success ms-1">EWB {{ $voucher->eway_bill_no }}</span>
            @elseif ($ewbNeeded)
                <span class="badge text-bg-warning ms-1">Needed: value over ₹{{ inr(\App\Support\EWayBill::THRESHOLD, 0) }}</span>
            @endif
        </summary>
        <form method="POST" action="{{ route('companies.gst-invoices.e-way-bill', [$company, $voucher]) }}" class="card-body row g-2 align-items-end">
            @csrf
            <div class="col-6 col-md-2">
                <label class="form-label small" for="ewb-mode">Mode</label>
                <select id="ewb-mode" name="mode" class="form-select">
                    @foreach (\App\Support\EWayBill::MODES as $code => $label)
                        <option value="{{ $code }}" @selected(old('mode', '1') === (string) $code)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label small" for="ewb-distance">Distance (km)</label>
                <input id="ewb-distance" type="number" min="1" max="4000" name="distance" value="{{ old('distance') }}" class="form-control" required>
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label small" for="ewb-vehicle">Vehicle no.</label>
                <input id="ewb-vehicle" name="vehicle_no" value="{{ old('vehicle_no') }}" class="form-control text-uppercase" maxlength="20" placeholder="MH12AB1234">
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label small" for="ewb-tid">Transporter ID</label>
                <input id="ewb-tid" name="transporter_id" value="{{ old('transporter_id') }}" class="form-control text-uppercase" maxlength="15">
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label small" for="ewb-tname">Transporter name</label>
                <input id="ewb-tname" name="transporter_name" value="{{ old('transporter_name') }}" class="form-control" maxlength="100">
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label small" for="ewb-doc">Transport doc no.</label>
                <input id="ewb-doc" name="trans_doc_no" value="{{ old('trans_doc_no') }}" class="form-control" maxlength="15">
            </div>
            <div class="col-12 d-flex justify-content-between align-items-center">
                <small class="text-secondary">Downloads the bulk-upload JSON for the e-way bill portal (ewaybillgst.gov.in).</small>
                <button class="btn btn-outline-primary">e-Way Bill JSON</button>
            </div>
        </form>
        <form method="POST" action="{{ route('companies.gst-invoices.e-way-bill.save', [$company, $voucher]) }}" class="card-body border-top row g-2 align-items-end">
            @csrf
            @method('PUT')
            <div class="col-12 small text-secondary">After generating it on the portal, record the e-way bill here so it prints on the invoice.</div>
            <div class="col-12 col-md-4">
                <label class="form-label small" for="ewb-no">E-way bill no.</label>
                <input id="ewb-no" name="eway_bill_no" value="{{ old('eway_bill_no', $voucher->eway_bill_no) }}" class="form-control" maxlength="14" inputmode="numeric" placeholder="12 digits">
            </div>
            <div class="col-6 col-md-3">
                <label class="form-label small" for="ewb-date">Generated on</label>
                <input id="ewb-date" type="date" name="eway_bill_date" value="{{ old('eway_bill_date', $voucher->eway_bill_date?->toDateString()) }}" class="form-control">
            </div>
            <div class="col-6 col-md-3">
                <label class="form-label small" for="ewb-valid">Valid until</label>
                <input id="ewb-valid" type="date" name="eway_bill_valid_until" value="{{ old('eway_bill_valid_until', $voucher->eway_bill_valid_until?->toDateString()) }}" class="form-control">
            </div>
            <div class="col-12 col-md-2 d-grid"><button class="btn btn-primary">Save</button></div>
        </form>
    </details>
    @endif
</div>
@endsection
