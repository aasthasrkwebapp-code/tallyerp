<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $voucher->typeLabel() }} #{{ $voucher->number }} | Receipt</title>
    <style>
        /* Thermal receipt: {{ $width }} mm paper, monospace, no margins. */
        @page { size: {{ $width }}mm auto; margin: 0; }
        body { margin: 0; background: #f5f5f5; color: #000; font: {{ $width === 58 ? '11px' : '12px' }}/1.35 "Courier New", monospace; }
        .receipt { width: {{ $width - 6 }}mm; margin: 12px auto; padding: 3mm; background: #fff; }
        .center { text-align: center; }
        .right { text-align: right; }
        .bold { font-weight: bold; }
        .rule { border-top: 1px dashed #000; margin: 4px 0; }
        table { width: 100%; border-collapse: collapse; }
        td { vertical-align: top; padding: 1px 0; }
        .actions { text-align: center; margin: 12px; font-family: sans-serif; }
        .actions button { padding: 6px 14px; }
        @media print {
            body { background: #fff; }
            .receipt { margin: 0; }
            .actions { display: none; }
        }
    </style>
</head>
<body>
    <div class="actions"><button type="button" onclick="window.print()">Print</button></div>

    <div class="receipt">
        <div class="center bold">{{ $company->legal_name ?: $company->name }}</div>
        @if ($company->address || $company->city)
            <div class="center">{{ collect([$company->address, $company->city, $company->pincode])->filter()->join(', ') }}</div>
        @endif
        @if ($company->gstin)<div class="center">GSTIN {{ $company->gstin }}</div>@endif
        @if ($company->phone)<div class="center">Ph {{ $company->phone }}</div>@endif

        <div class="rule"></div>
        <div class="center bold">{{ $voucher->type === 'sales' ? 'TAX INVOICE' : strtoupper($voucher->typeLabel()) }}</div>
        <div>No: {{ $voucher->number }}/{{ $voucher->financialYearLabel() }}</div>
        <div>Date: {{ $voucher->date->format('d-m-Y') }}</div>
        @if ($voucher->party)<div>To: {{ $voucher->party->name }}</div>@endif
        <div class="rule"></div>

        <table>
            @foreach ($voucher->gstLines as $line)
                <tr><td colspan="2">{{ $line->description ?: ($line->stockItem?->name ?? $line->account->name) }}</td></tr>
                <tr>
                    <td>
                        @if ($line->quantity !== null)
                            {{ rtrim(rtrim(number_format((float) $line->quantity, 3), '0'), '.') }} x {{ inr($line->item_rate) }}
                        @endif
                        @if ((float) $line->discount_percent > 0) -{{ (float) $line->discount_percent }}% @endif
                    </td>
                    <td class="right">{{ inr($line->taxable) }}</td>
                </tr>
            @endforeach
        </table>
        <div class="rule"></div>

        @php
            $sum = fn ($col) => $voucher->gstLines->sum(fn ($l) => (float) $l->$col);
        @endphp
        <table>
            <tr><td>Taxable</td><td class="right">{{ inr($sum('taxable')) }}</td></tr>
            @if ($sum('igst') > 0)
                <tr><td>IGST</td><td class="right">{{ inr($sum('igst')) }}</td></tr>
            @else
                <tr><td>CGST</td><td class="right">{{ inr($sum('cgst')) }}</td></tr>
                <tr><td>SGST</td><td class="right">{{ inr($sum('sgst')) }}</td></tr>
            @endif
            <tr class="bold"><td>TOTAL</td><td class="right">₹ {{ inr($voucher->amount) }}</td></tr>
        </table>
        <div class="rule"></div>
        <div class="center">Thank you! Visit again.</div>
    </div>
</body>
</html>
