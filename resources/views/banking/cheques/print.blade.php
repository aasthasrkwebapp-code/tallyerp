<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Cheque {{ $voucher->instrument_number }}</title>
<style>
    @page { size: {{ $layout['width'] }}mm {{ $layout['height'] }}mm; margin: 0; }
    html, body { margin: 0; padding: 0; background: #fff; color: #000; }
    body { font-family: Arial, Helvetica, sans-serif; }
    .leaf { position: relative; width: {{ $layout['width'] }}mm; height: {{ $layout['height'] }}mm; overflow: hidden; }
    .screen .leaf { border: 1px dashed #999; margin: 12px auto; }
    .f { position: absolute; white-space: nowrap; font-size: 11pt; }
    .date { letter-spacing: 2.55mm; font-size: 12pt; }
    .words { white-space: normal; width: {{ max(40, $layout['width'] - $layout['words_x'] - 50) }}mm; line-height: 7.5mm; }
    .amount { font-size: 12pt; font-weight: bold; }
    .crossing { position: absolute; left: 4mm; top: 16mm; transform: rotate(-30deg); transform-origin: left top; border-top: 1px solid #000; border-bottom: 1px solid #000; padding: 0 3mm; font-size: 9pt; font-weight: bold; }
    .bar { text-align: center; font: 14px Arial; padding: 10px; }
    @media print { .bar { display: none; } .screen .leaf { border: 0; margin: 0; } }
</style>
</head>
<body class="screen">
<div class="bar">Cheque {{ $voucher->instrument_number }} · {{ $voucher->instrumentAccount?->name }} · Set the printer to the cheque size with no margins and 100% scale.
    <button type="button" onclick="window.print()">Print</button></div>
<div class="leaf">
    @if ($layout['ac_payee'])<div class="crossing">A/c Payee</div>@endif
    <div class="f date" style="left: {{ $layout['date_x'] }}mm; top: {{ $layout['date_y'] }}mm">{{ $date }}</div>
    <div class="f" style="left: {{ $layout['payee_x'] }}mm; top: {{ $layout['payee_y'] }}mm">{{ $voucher->payee_name ?? '' }}</div>
    <div class="f words" style="left: {{ $layout['words_x'] }}mm; top: {{ $layout['words_y'] }}mm">{{ $words }}</div>
    <div class="f amount" style="left: {{ $layout['amount_x'] }}mm; top: {{ $layout['amount_y'] }}mm">**{{ $amount }}/-</div>
</div>
</body>
</html>
