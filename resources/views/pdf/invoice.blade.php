@php
    use App\Support\Money;
    use App\Support\Decimal;
    $title = match ($invoice->kind) {
        'sales' => $invoice->tax_mode === 'none' ? 'Bill of Supply' : 'Tax Invoice',
        'purchase' => 'Purchase Bill',
        default => $config['label'],
    };
    $stateCode = fn ($name) => ($c = array_search($name, config('india.states'), true)) === false ? null : str_pad((string) $c, 2, '0', STR_PAD_LEFT);
    $logo = $company->logo_path ? storage_path('app/public/'.$company->logo_path) : null;
@endphp
@extends('pdf.layout', ['footerLeft' => $title.' '.$invoice->displayNumber().' · '.$company->displayName()])
@section('title', $title.' '.$invoice->displayNumber())

@section('content')
<table style="margin-bottom: 6pt">
    <tr>
        <td style="width: 60%">
            @if ($logo && is_file($logo))<img src="{{ $logo }}" alt="" style="max-height: 45pt; margin-bottom: 3pt"><br>@endif
            <h1>{{ $company->displayName() }}</h1>
            <div class="pre">{{ $company->address }}</div>
            <div>{{ trim(implode(' ', array_filter([$company->city, $company->pincode]))) }} {{ $company->state }}</div>
            @if ($company->gstin)<div>GSTIN: <b>{{ $company->gstin }}</b></div>@endif
            @if ($company->phone || $company->email)<div>{{ implode(' · ', array_filter([$company->phone, $company->email])) }}</div>@endif
        </td>
        <td class="right" style="width: 40%">
            <div class="bold upper" style="font-size: 12pt">{{ $title }}</div>
            @if ($invoice->status() !== 'posted')<div class="stamp">{{ strtoupper(\App\Models\Voucher::STATUSES[$invoice->status()]) }}</div>@endif
            <div>No. <b>{{ $invoice->displayNumber() }}</b></div>
            <div>Date {{ $invoice->date->format('d-m-Y') }}</div>
            @if ($invoice->due_date)<div>Due {{ $invoice->due_date->format('d-m-Y') }}</div>@endif
            @if ($invoice->ewayBill?->ewb_no)<div>E-way bill {{ $invoice->ewayBill->ewb_no }}</div>@endif
        </td>
    </tr>
</table>
@if ($invoice->eInvoice && ! $invoice->eInvoice->cancelled_at)
<table style="margin-bottom: 6pt">
    <tr>
        <td class="small">IRN: <b>{{ $invoice->eInvoice->irn }}</b><br>Ack no. {{ $invoice->eInvoice->ack_no }} · Ack date {{ $invoice->eInvoice->ack_date->format('d-m-Y H:i') }}</td>
        @if ($invoice->eInvoice->signed_qr)<td class="right" style="width: 80pt"><img src="data:image/svg+xml;base64,{{ base64_encode(\App\Support\Totp::qrSvg($invoice->eInvoice->signed_qr, 200)) }}" alt="" style="width: 75pt; height: 75pt"></td>@endif
    </tr>
</table>
@endif

<table class="grid" style="margin-bottom: 6pt">
    <tr>
        <td style="width: 50%">
            <div class="muted small">{{ $config['party_group'] === 'sundry_debtors' ? 'Bill to' : 'Supplier' }}</div>
            <div class="bold">{{ $invoice->party_name }}</div>
            <div class="pre">{{ $invoice->billing_address }}</div>
            @if ($invoice->party_gstin)<div>GSTIN: {{ $invoice->party_gstin }}</div>@endif
        </td>
        <td>
            @if ($invoice->shipping_address)<div class="muted small">Ship to</div><div class="pre">{{ $invoice->shipping_address }}</div>@endif
            @if ($invoice->place_of_supply)<div>{{ $config['tax'] === 'input' ? 'Supplier state' : 'Place of supply' }}: {{ $invoice->place_of_supply }} @if ($stateCode($invoice->place_of_supply))({{ $stateCode($invoice->place_of_supply) }})@endif</div>@endif
            @if ($invoice->reference)<div>{{ $config['tax'] === 'input' ? 'Supplier invoice' : 'Reference' }}: {{ $invoice->reference }} {{ $invoice->reference_date?->format('d-m-Y') }}</div>@endif
            @if ($invoice->originalInvoice)<div>Against: {{ $invoice->originalInvoice->displayNumber() }} dated {{ $invoice->originalInvoice->date->format('d-m-Y') }}</div>@endif
        </td>
    </tr>
</table>

<table class="grid" style="margin-bottom: 6pt">
    <thead>
        <tr><th>#</th><th>Description</th><th>HSN/SAC</th><th class="num">Qty</th><th class="num">Rate</th><th class="num">Disc %</th><th class="num">Taxable</th>
            @if ($invoice->tax_mode !== 'none')<th class="num">GST %</th>@endif<th class="num">Amount</th></tr>
    </thead>
    <tbody>
    @foreach ($invoice->items as $item)
        <tr>
            <td>{{ $item->line_no }}</td>
            <td>{{ $item->description }}@if ($item->batch_no)<br><small>Batch {{ $item->batch_no }}@if ($item->expiry_date) · exp {{ $item->expiry_date->format('d-m-Y') }}@endif</small>@endif @if ($item->serials)<br><small>S/N: {{ implode(', ', $item->serialList()) }}</small>@endif</td>
            <td>{{ $item->hsn_sac }}</td>
            <td class="num">{{ Decimal::trim($item->quantity) }} {{ $item->unit }}</td>
            <td class="num">{{ Money::format($item->rate) }}</td>
            <td class="num">{{ (float) $item->discount_percent ? Decimal::trim($item->discount_percent) : '' }}</td>
            <td class="num">{{ Money::format($item->taxable_amount) }}</td>
            @if ($invoice->tax_mode !== 'none')<td class="num">{{ Decimal::trim($item->gst_rate) }}</td>@endif
            <td class="num">{{ Money::format($item->total_amount) }}</td>
        </tr>
    @endforeach
    </tbody>
</table>

<table>
    <tr>
        <td style="width: 58%; padding-right: 8pt; vertical-align: top">
            @if ($invoice->tax_mode !== 'none')
                <table class="grid small" style="margin-bottom: 5pt">
                    <thead><tr><th>HSN/SAC</th><th class="num">Rate</th><th class="num">Taxable</th>@if ($invoice->tax_mode === 'intra')<th class="num">CGST</th><th class="num">SGST</th>@else<th class="num">IGST</th>@endif</tr></thead>
                    <tbody>
                    @foreach ($taxSummary as $row)
                        <tr><td>{{ $row['hsn'] }}</td><td class="num">{{ $row['rate'] }}%</td><td class="num">{{ Money::format($row['taxable'], paiseInput: true) }}</td>
                            @if ($invoice->tax_mode === 'intra')<td class="num">{{ Money::format($row['cgst'], paiseInput: true) }}</td><td class="num">{{ Money::format($row['sgst'], paiseInput: true) }}</td>
                            @else<td class="num">{{ Money::format($row['igst'], paiseInput: true) }}</td>@endif</tr>
                    @endforeach
                    </tbody>
                </table>
            @endif
            <div><b>Amount in words:</b> {{ \App\Support\NumberToWords::rupees(Money::toPaise($invoice->grand_total)) }}</div>
            @if ($bank)<div class="small" style="margin-top: 4pt"><b>Bank details:</b> {{ $bank->bank_name ?? $bank->name }}, A/c {{ $bank->bank_account_number }}, IFSC {{ $bank->ifsc }}{{ $bank->bank_branch ? ', '.$bank->bank_branch : '' }}</div>@endif
            @if ($invoice->terms)<div class="small" style="margin-top: 4pt"><b>Terms:</b><div class="pre">{{ $invoice->terms }}</div></div>@endif
        </td>
        <td style="width: 42%; vertical-align: top">
            <table class="grid">
                <tr><td>Taxable value</td><td class="num">{{ Money::format($invoice->taxable_total) }}</td></tr>
                @if ((float) $invoice->cgst_total)<tr><td>CGST</td><td class="num">{{ Money::format($invoice->cgst_total) }}</td></tr>@endif
                @if ((float) $invoice->sgst_total)<tr><td>SGST / UTGST</td><td class="num">{{ Money::format($invoice->sgst_total) }}</td></tr>@endif
                @if ((float) $invoice->igst_total)<tr><td>IGST</td><td class="num">{{ Money::format($invoice->igst_total) }}</td></tr>@endif
                @if ((float) $invoice->round_off)<tr><td>Round off</td><td class="num">{{ Money::format($invoice->round_off) }}</td></tr>@endif
                <tr class="bold"><td>Total</td><td class="num">₹ {{ Money::format($invoice->grand_total) }}</td></tr>
            </table>
            <div class="right" style="margin-top: 30pt">For {{ $company->displayName() }}<br><br><br>Authorised signatory</div>
        </td>
    </tr>
</table>
@if ($company->setting('invoice_footer'))<div class="center small muted" style="margin-top: 10pt">{{ $company->setting('invoice_footer') }}</div>@endif
@endsection
