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
    $template = $company->invoice_template ?: 'classic';
    $ownInvoice = in_array($voucher->type, ['sales', 'debit_note'], true); // documents we issue to the party
    $upiLink = $voucher->type === 'sales' ? $company->upiPaymentLink((float) $voucher->amount, 'Invoice '.$voucher->number.'/'.$voucher->financialYearLabel()) : null;
@endphp
<style>
    /* Invoice designs (Company → Edit → Invoice printing). */
    .invoice-modern .invoice-title { background: var(--erp-accent, #4f46e5); color: #fff; padding: .6rem 1rem; border-radius: .4rem; text-align: left !important; }
    .invoice-modern .table-bordered, .invoice-modern .table-bordered td, .invoice-modern .table-bordered th { border-left: 0; border-right: 0; }
    .invoice-modern thead th { background: #eef2ff !important; }
    .invoice-compact { font-size: .82rem; }
    .invoice-compact .card-body { padding: 1rem !important; }
    .invoice-compact .table td, .invoice-compact .table th { padding: .25rem .4rem; }
    @media print {
        .invoice-sheet { box-shadow: none !important; }
        .invoice-modern .invoice-title { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    }
</style>
<div class="container-fluid py-3">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4 d-print-none">
        <h2 class="h4 mb-0">{{ $voucher->typeLabel() }} #{{ $voucher->number }}</h2>
        <div class="d-flex flex-wrap gap-2">
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

    <div class="card border-0 shadow-sm invoice-sheet invoice-{{ $template }}">
        <div class="card-body p-3 p-md-4">
            <div class="text-center mb-3 invoice-title">
                <div class="h5 mb-0">{{ ['sales' => 'TAX INVOICE', 'purchase' => 'PURCHASE INVOICE', 'credit_note' => 'CREDIT NOTE', 'debit_note' => 'DEBIT NOTE'][$voucher->type] ?? 'INVOICE' }}</div>
            </div>

            <div class="row g-3 mb-3">
                <div class="col-12 col-md-6">
                    <div class="text-secondary small">{{ in_array($voucher->type, ['sales', 'credit_note']) ? 'From' : 'Bill to' }}</div>
                    <div class="fw-semibold">{{ $company->legal_name ?: $company->name }}</div>
                    @if ($company->address || $company->city)
                        <div class="small">{{ collect([$company->address, $company->city, $company->pincode])->filter()->join(', ') }}</div>
                    @endif
                    @if ($company->gstin)<div class="small">GSTIN: {{ $company->gstin }}</div>@endif
                    <div class="small">State: {{ \App\Support\GstStates::name($company->stateCode()) }} ({{ $company->stateCode() }})</div>
                </div>
                <div class="col-12 col-md-6">
                    <div class="text-secondary small">{{ in_array($voucher->type, ['sales', 'credit_note']) ? 'Bill to' : 'Supplier' }}</div>
                    <div class="fw-semibold">{{ $voucher->party?->name }}</div>
                    @if ($voucher->party?->address || $voucher->party?->city)
                        <div class="small">{{ collect([$voucher->party->address, $voucher->party->city, $voucher->party->pincode])->filter()->join(', ') }}</div>
                    @endif
                    @if ($voucher->party?->gstin)<div class="small">GSTIN: {{ $voucher->party->gstin }}</div>@endif
                    <div class="small">Place of supply: {{ \App\Support\GstStates::name($voucher->place_of_supply) }} ({{ $voucher->place_of_supply }})</div>
                    @if ($voucher->eway_bill_no)
                        <div class="small">E-way bill: {{ chunk_split($voucher->eway_bill_no, 4, ' ') }} · {{ $voucher->eway_bill_date?->format('d-m-Y') }}@if ($voucher->eway_bill_valid_until) · valid till {{ $voucher->eway_bill_valid_until->format('d-m-Y') }}@endif</div>
                    @endif
                    <div class="small">No. {{ $voucher->number }}/{{ $voucher->financialYearLabel() }} ·{{ $voucher->date->format('d-m-Y') }}
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
                                    @if ($line->batch_no)
                                        <div class="small text-secondary">Batch {{ $line->batch_no }}@if ($line->expiry_date) · exp {{ $line->expiry_date->format('m/Y') }}@endif</div>
                                    @endif
                                    @if ($line->serial_numbers)
                                        <div class="small text-secondary">S/N: {{ $line->serial_numbers }}</div>
                                    @endif
                                    @if ((float) $line->discount_percent > 0)
                                        <div class="small text-secondary">{{ inr($line->gross_amount) }} less {{ (float) $line->discount_percent }}% discount</div>
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

            <p class="small mb-3"><span class="text-secondary">Amount in words:</span> <span class="fw-semibold">{{ \App\Support\AmountInWords::rupees($voucher->amount) }}</span></p>

            @if ($voucher->narration)
                <p class="small mb-3"><span class="text-secondary">Narration:</span> {{ $voucher->narration }}</p>
            @endif

            @if ($ownInvoice)
                <div class="row g-3 align-items-end small">
                    <div class="col-12 col-md-5">
                        @if ($company->bank_account_no)
                            <div class="fw-semibold">Bank details</div>
                            <div>{{ $company->bank_name }}</div>
                            <div>A/c {{ $company->bank_account_no }} · IFSC {{ $company->bank_ifsc }}</div>
                        @endif
                        @if ($company->invoice_terms)
                            <div class="fw-semibold mt-2">Terms &amp; conditions</div>
                            <div style="white-space: pre-line">{{ $company->invoice_terms }}</div>
                        @endif
                    </div>
                    <div class="col-6 col-md-3 text-center">
                        @if ($upiLink)
                            <div id="upi-qr" class="d-inline-block" data-link="{{ $upiLink }}" role="img" aria-label="UPI payment QR code"></div>
                            <div>Scan to pay ₹ {{ $money($voucher->amount) }}<br><span class="text-secondary">{{ $company->upi_id }}</span></div>
                        @endif
                    </div>
                    <div class="col-6 col-md-4 text-end">
                        <div>For {{ $company->legal_name ?: $company->name }}</div>
                        <div style="height: 3rem"></div>
                        <div class="border-top pt-1">Authorised signatory</div>
                    </div>
                </div>
            @endif
        </div>
    </div>

    @if ($upiLink)
        <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
        <script>
            (() => {
                const box = document.getElementById('upi-qr');
                if (box && window.QRCode) new QRCode(box, { text: box.dataset.link, width: 110, height: 110, correctLevel: QRCode.CorrectLevel.M });
            })();
        </script>
    @endif

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
