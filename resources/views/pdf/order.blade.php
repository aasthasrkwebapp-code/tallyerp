@php
    use App\Support\Money;
    use App\Support\Decimal;
    $title = $config['label'];
    $details = $order->party?->partyDetails;
    $logo = $company->logo_path ? storage_path('app/public/'.$company->logo_path) : null;
@endphp
@extends('pdf.layout', ['footerLeft' => $title.' '.$order->number.' · '.$company->displayName()])
@section('title', $title.' '.$order->number)

@section('content')
@if ($printable ?? false)
    <div style="margin-bottom: 8pt" class="no-print"><button onclick="window.print()">Print</button></div>
    <style>@media print { .no-print { display: none } } .footer { display: none }</style>
@endif
<table style="margin-bottom: 6pt">
    <tr>
        <td style="width: 60%">
            @if (! ($printable ?? false) && $logo && is_file($logo))<img src="{{ $logo }}" alt="" style="max-height: 45pt; margin-bottom: 3pt"><br>@endif
            <h1>{{ $company->displayName() }}</h1>
            <div class="pre">{{ $company->address }}</div>
            <div>{{ trim(implode(' ', array_filter([$company->city, $company->pincode]))) }} {{ $company->state }}</div>
            @if ($company->gstin)<div>GSTIN: <b>{{ $company->gstin }}</b></div>@endif
        </td>
        <td class="right" style="width: 40%">
            <div class="bold upper" style="font-size: 12pt">{{ $title }}</div>
            @if (! $order->isOpen())<div class="stamp">{{ strtoupper($order->status) }}</div>@endif
            <div>No. <b>{{ $order->number }}</b></div>
            <div>Date {{ $order->date->format('d-m-Y') }}</div>
            @if ($order->delivery_date)<div>{{ $config['date_label'] ?? 'Delivery by' }} {{ $order->delivery_date->format('d-m-Y') }}</div>@endif
        </td>
    </tr>
</table>

<table class="grid" style="margin-bottom: 6pt">
    <tr>
        <td style="width: 50%">
            <div class="muted small">{{ $config['party_group'] === 'sundry_debtors' ? 'Customer' : 'Supplier' }}</div>
            <div class="bold">{{ $order->party_name }}</div>
            <div class="pre">{{ $details?->fullAddress() }}</div>
            @if ($details?->gstin)<div>GSTIN: {{ $details->gstin }}</div>@endif
        </td>
        <td>
            @if ($order->reference)<div>{{ ($config['quotation'] ?? false) ? 'Enquiry' : ($config['invoice_kind'] === 'sales' ? "Customer's PO" : 'Quotation') }}: {{ $order->reference }} {{ $order->reference_date?->format('d-m-Y') }}</div>@endif
            @if ($order->place_of_supply)<div>Place of supply: {{ $order->place_of_supply }}</div>@endif
        </td>
    </tr>
</table>

<table class="grid" style="margin-bottom: 6pt">
    <thead>
        <tr><th>#</th><th>Description</th><th>HSN/SAC</th><th class="num">Qty</th><th class="num">Rate</th><th class="num">Disc %</th><th class="num">Taxable</th>
            @if ($order->tax_mode !== 'none')<th class="num">GST %</th>@endif<th class="num">Amount</th></tr>
    </thead>
    <tbody>
    @foreach ($order->items as $item)
        <tr>
            <td>{{ $item->line_no }}</td>
            <td>{{ $item->description }}</td>
            <td>{{ $item->hsn_sac }}</td>
            <td class="num">{{ Decimal::trim($item->quantity) }} {{ $item->unit }}</td>
            <td class="num">{{ Money::format($item->rate) }}</td>
            <td class="num">{{ (float) $item->discount_percent ? Decimal::trim($item->discount_percent) : '' }}</td>
            <td class="num">{{ Money::format($item->taxable_amount) }}</td>
            @if ($order->tax_mode !== 'none')<td class="num">{{ Decimal::trim($item->gst_rate) }}</td>@endif
            <td class="num">{{ Money::format($item->total_amount) }}</td>
        </tr>
    @endforeach
    </tbody>
</table>

<table>
    <tr>
        <td style="width: 58%; padding-right: 8pt; vertical-align: top">
            <div><b>Amount in words:</b> {{ \App\Support\NumberToWords::rupees(Money::toPaise($order->grand_total)) }}</div>
            @if ($order->terms)<div class="small" style="margin-top: 4pt"><b>Terms:</b><div class="pre">{{ $order->terms }}</div></div>@endif
        </td>
        <td style="width: 42%; vertical-align: top">
            <table class="grid">
                <tr><td>Taxable value</td><td class="num">{{ Money::format($order->taxable_total) }}</td></tr>
                @if ((float) $order->cgst_total)<tr><td>CGST</td><td class="num">{{ Money::format($order->cgst_total) }}</td></tr>@endif
                @if ((float) $order->sgst_total)<tr><td>SGST / UTGST</td><td class="num">{{ Money::format($order->sgst_total) }}</td></tr>@endif
                @if ((float) $order->igst_total)<tr><td>IGST</td><td class="num">{{ Money::format($order->igst_total) }}</td></tr>@endif
                <tr class="bold"><td>Total</td><td class="num">₹ {{ Money::format($order->grand_total) }}</td></tr>
            </table>
            <div class="right" style="margin-top: 30pt">For {{ $company->displayName() }}<br><br><br>Authorised signatory</div>
        </td>
    </tr>
</table>
@endsection
