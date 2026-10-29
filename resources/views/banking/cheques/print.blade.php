<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{{ $leaves->count() === 1 ? 'Cheque '.$leaves->first()->voucher->instrument_number : $leaves->count().' cheques' }}</title>
<style>
    @page { size: {{ $layout['width'] }}mm {{ $layout['height'] }}mm; margin: 0; }
    html, body { margin: 0; padding: 0; background: #fff; color: #000; }
    body { font-family: Arial, Helvetica, sans-serif; }
    .leaf { position: relative; width: {{ $layout['width'] }}mm; height: {{ $layout['height'] }}mm; overflow: hidden; page-break-after: always; break-after: page; }
    .leaf:last-of-type { page-break-after: auto; break-after: auto; }
    .screen .leaf { border: 1px dashed #999; margin: 12px auto; }
    .f { position: absolute; white-space: nowrap; font-size: 11pt; }
    .date { letter-spacing: 2.55mm; font-size: 12pt; }
    .words { white-space: normal; line-height: 7.5mm; }
    .amount { font-size: 12pt; font-weight: bold; }
    .crossing { position: absolute; left: 4mm; top: 16mm; transform: rotate(-30deg); transform-origin: left top; border-top: 1px solid #000; border-bottom: 1px solid #000; padding: 0 3mm; font-size: 9pt; font-weight: bold; }
    .bar { text-align: center; font: 14px Arial; padding: 10px; }
    .bar .warn { color: #a15c00; display: block; margin-top: 4px; }
    .tag { text-align: center; font: 12px Arial; color: #555; margin-top: 12px; }
    @media print { .bar, .tag { display: none; } .screen .leaf { border: 0; margin: 0; } }
</style>
</head>
<body class="screen">
<div class="bar">
    @if ($leaves->count() === 1)
        Cheque {{ $leaves->first()->voucher->instrument_number }} · {{ $leaves->first()->voucher->instrumentAccount?->name }}
    @else
        {{ $leaves->count() }} cheques, one per page · {{ $leaves->pluck('voucher.instrumentAccount.name')->unique()->implode(', ') }}
    @endif
    · Set the printer to the cheque size with no margins and 100% scale.
    <button type="button" onclick="window.print()">Print</button>
    @php($reprints = $leaves->where('reprint', true))
    @if ($reprints->isNotEmpty())<span class="warn">Printed before: {{ $reprints->pluck('voucher.instrument_number')->implode(', ') }}. Mark the earlier leaf spoilt if it was not used.</span>@endif
</div>
@foreach ($leaves as $l)
    @if ($leaves->count() > 1)<div class="tag">Cheque {{ $l->voucher->instrument_number }} · {{ $l->voucher->payee_name }} · {{ $l->amount }}</div>@endif
    <div class="leaf">
        @if ($l->layout['ac_payee'])<div class="crossing">A/c Payee</div>@endif
        <div class="f date" style="left: {{ $l->layout['date_x'] }}mm; top: {{ $l->layout['date_y'] }}mm">{{ $l->date }}</div>
        <div class="f" style="left: {{ $l->layout['payee_x'] }}mm; top: {{ $l->layout['payee_y'] }}mm">{{ $l->voucher->payee_name ?? '' }}</div>
        <div class="f words" style="left: {{ $l->layout['words_x'] }}mm; top: {{ $l->layout['words_y'] }}mm; width: {{ max(40, $l->layout['width'] - $l->layout['words_x'] - 50) }}mm">{{ $l->words }}</div>
        <div class="f amount" style="left: {{ $l->layout['amount_x'] }}mm; top: {{ $l->layout['amount_y'] }}mm">**{{ $l->amount }}/-</div>
    </div>
@endforeach
</body>
</html>
