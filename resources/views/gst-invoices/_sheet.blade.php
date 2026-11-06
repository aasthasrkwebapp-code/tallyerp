{{-- The printable invoice: used by the invoice page and the public shared link. Expects $company, $voucher. --}}
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
                        @if ((float) $voucher->bill_discount_percent > 0)
                            <tr class="fw-normal small text-secondary">
                                <td colspan="99">Taxable values are after a {{ (float) $voucher->bill_discount_percent }}% discount on the whole bill.</td>
                            </tr>
                        @endif
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

