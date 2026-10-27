<!DOCTYPE html>
@php
    use App\Support\Money;
    use App\Support\Decimal;
    $title = match ($invoice->kind) {
        'sales' => $invoice->tax_mode === 'none' ? 'Bill of Supply' : 'Tax Invoice',
        'purchase' => 'Purchase Bill',
        default => $config['label'],
    };
    $stateCode = fn ($name) => ($c = array_search($name, config('india.states'), true)) === false ? null : str_pad((string) $c, 2, '0', STR_PAD_LEFT);
@endphp
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }} {{ $invoice->displayNumber() }} | {{ $company->displayName() }}</title>
    <link rel="stylesheet" href="{{ asset('vendor/bootstrap/css/bootstrap.min.css') }}">
    <style>
        body { background: #f3f4f6; font-size: 13px; color: #111; }
        .sheet { background: #fff; max-width: 210mm; margin: 1rem auto; padding: 12mm; box-shadow: 0 1px 4px rgba(0,0,0,.1); }
        .sheet table { width: 100%; }
        .bordered, .bordered th, .bordered td { border: 1px solid #555; border-collapse: collapse; }
        .bordered th, .bordered td { padding: 3px 5px; vertical-align: top; }
        .num { text-align: right; white-space: nowrap; font-variant-numeric: tabular-nums; }
        .draft-mark { color: #b91c1c; border: 2px solid #b91c1c; padding: 2px 8px; font-weight: 700; display: inline-block; }
        @media print {
            body { background: #fff; }
            .sheet { box-shadow: none; margin: 0; padding: 0; max-width: none; }
            .no-print { display: none !important; }
            @page { size: A4; margin: 10mm; }
        }
        @media (max-width: 600px) { .sheet { padding: 4mm; margin: 0; } body { font-size: 11px; } }
    </style>
</head>
<body>
<div class="no-print text-center my-2">
    <button class="btn btn-primary btn-sm" onclick="window.print()">Print</button>
    <a class="btn btn-outline-secondary btn-sm" href="{{ route('invoices.pdf', $invoice) }}">Download PDF</a>
    <a class="btn btn-outline-secondary btn-sm" href="{{ route('invoices.show', $invoice) }}">Back</a>
</div>
<div class="sheet">
    <div class="d-flex justify-content-between align-items-start mb-2">
        <div>
            @if ($company->logo_path)<img src="{{ asset('storage/'.$company->logo_path) }}" alt="" style="max-height: 60px" class="mb-1">@endif
            <div class="fs-5 fw-bold">{{ $company->displayName() }}</div>
            <div style="white-space: pre-line">{{ $company->address }}</div>
            <div>{{ trim(implode(' ', array_filter([$company->city, $company->pincode]))) }} {{ $company->state }}</div>
            @if ($company->gstin)<div>GSTIN: <strong>{{ $company->gstin }}</strong></div>@endif
            @if ($company->phone || $company->email)<div>{{ implode(' · ', array_filter([$company->phone, $company->email])) }}</div>@endif
        </div>
        <div class="text-end">
            <div class="fs-5 fw-bold text-uppercase">{{ $title }}</div>
            @if ($invoice->status() !== 'posted')<div class="draft-mark">{{ strtoupper(\App\Models\Voucher::STATUSES[$invoice->status()]) }}</div>@endif
            <div>No. <strong>{{ $invoice->displayNumber() }}</strong></div>
            <div>Date {{ $invoice->date->format('d-m-Y') }}</div>
            @if ($invoice->due_date)<div>Due {{ $invoice->due_date->format('d-m-Y') }}</div>@endif
        </div>
    </div>

    <table class="bordered mb-2">
        <tr>
            <td style="width: 50%">
                <div class="text-secondary small">{{ $config['party_group'] === 'sundry_debtors' ? 'Bill to' : 'Supplier' }}</div>
                <div class="fw-bold">{{ $invoice->party_name }}</div>
                <div style="white-space: pre-line">{{ $invoice->billing_address }}</div>
                @if ($invoice->party_gstin)<div>GSTIN: {{ $invoice->party_gstin }}</div>@endif
            </td>
            <td>
                @if ($invoice->shipping_address)
                    <div class="text-secondary small">Ship to</div>
                    <div style="white-space: pre-line">{{ $invoice->shipping_address }}</div>
                @endif
                @if ($invoice->place_of_supply)
                    <div>{{ $config['tax'] === 'input' ? 'Supplier state' : 'Place of supply' }}: {{ $invoice->place_of_supply }} @if ($stateCode($invoice->place_of_supply))({{ $stateCode($invoice->place_of_supply) }})@endif</div>
                @endif
                @if ($invoice->reference)<div>{{ $config['tax'] === 'input' ? 'Supplier invoice' : 'Reference' }}: {{ $invoice->reference }} {{ $invoice->reference_date?->format('d-m-Y') }}</div>@endif
                @if ($invoice->originalInvoice)<div>Against: {{ $invoice->originalInvoice->displayNumber() }} dated {{ $invoice->originalInvoice->date->format('d-m-Y') }}</div>@endif
            </td>
        </tr>
    </table>

    <table class="bordered mb-2">
        <thead>
            <tr><th>#</th><th>Description</th><th>HSN/SAC</th><th class="num">Qty</th><th class="num">Rate</th><th class="num">Disc %</th><th class="num">Taxable</th>
                @if ($invoice->tax_mode !== 'none')<th class="num">GST %</th>@endif<th class="num">Amount</th></tr>
        </thead>
        <tbody>
        @foreach ($invoice->items as $item)
            <tr>
                <td>{{ $item->line_no }}</td>
                <td>{{ $item->description }}@include('inventory._tracking', ['line' => $item])</td>
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

    <div class="row g-2">
        <div class="col-7">
            @if ($invoice->tax_mode !== 'none')
                <table class="bordered mb-2 small">
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
            <div><strong>Amount in words:</strong> {{ \App\Support\NumberToWords::rupees(Money::toPaise($invoice->grand_total)) }}</div>
            @if ($bank)
                <div class="mt-2 small"><strong>Bank details:</strong> {{ $bank->bank_name ?? $bank->name }}, A/c {{ $bank->bank_account_number }}, IFSC {{ $bank->ifsc }}{{ $bank->bank_branch ? ', '.$bank->bank_branch : '' }}</div>
            @endif
            @if ($invoice->terms)<div class="mt-2 small"><strong>Terms:</strong><div style="white-space: pre-line">{{ $invoice->terms }}</div></div>@endif
        </div>
        <div class="col-5">
            <table class="bordered">
                <tr><td>Taxable value</td><td class="num">{{ Money::format($invoice->taxable_total) }}</td></tr>
                @if ((float) $invoice->cgst_total)<tr><td>CGST</td><td class="num">{{ Money::format($invoice->cgst_total) }}</td></tr>@endif
                @if ((float) $invoice->sgst_total)<tr><td>SGST / UTGST</td><td class="num">{{ Money::format($invoice->sgst_total) }}</td></tr>@endif
                @if ((float) $invoice->igst_total)<tr><td>IGST</td><td class="num">{{ Money::format($invoice->igst_total) }}</td></tr>@endif
                @if ((float) $invoice->round_off)<tr><td>Round off</td><td class="num">{{ Money::format($invoice->round_off) }}</td></tr>@endif
                <tr class="fw-bold"><td>Total</td><td class="num">₹{{ Money::format($invoice->grand_total) }}</td></tr>
            </table>
            <div class="text-end mt-5 pt-3">For {{ $company->displayName() }}<br><br><br>Authorised signatory</div>
        </div>
    </div>
    @if ($company->setting('invoice_footer'))<div class="text-center small text-secondary mt-3">{{ $company->setting('invoice_footer') }}</div>@endif
</div>
</body>
</html>
