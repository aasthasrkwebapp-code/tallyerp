@php
    use App\Support\Decimal;
    $title = $config['label'];
    $details = $note->party?->partyDetails;
@endphp
@extends('pdf.layout', ['footerLeft' => $title.' '.$note->number.' · '.$company->displayName()])
@section('title', $title.' '.$note->number)

@section('content')
@if ($printable ?? false)
    <div style="margin-bottom: 8pt" class="no-print"><button onclick="window.print()">Print</button></div>
    <style>@media print { .no-print { display: none } } .footer { display: none }</style>
@endif
<table style="margin-bottom: 6pt">
    <tr>
        <td style="width: 60%">
            <h1>{{ $company->displayName() }}</h1>
            <div class="pre">{{ $company->address }}</div>
            <div>{{ trim(implode(' ', array_filter([$company->city, $company->pincode]))) }} {{ $company->state }}</div>
            @if ($company->gstin)<div>GSTIN: <b>{{ $company->gstin }}</b></div>@endif
        </td>
        <td class="right" style="width: 40%">
            <div class="bold upper" style="font-size: 12pt">{{ $title }}</div>
            @unless ($note->isPosted())<div class="stamp">CANCELLED</div>@endunless
            <div>No. <b>{{ $note->number }}</b></div>
            <div>Date {{ $note->date->format('d-m-Y') }}</div>
            @if ($note->reference)<div>{{ $config['direction'] === 'out' ? 'Vehicle / LR' : 'Challan' }}: {{ $note->reference }}</div>@endif
        </td>
    </tr>
</table>

<table class="grid" style="margin-bottom: 6pt">
    <tr><td>
        <div class="muted small">{{ $config['direction'] === 'out' ? 'Deliver to' : 'Received from' }}</div>
        <div class="bold">{{ $note->party_name }}</div>
        <div class="pre">{{ $details?->shipping_address ?: $details?->fullAddress() }}</div>
        @if ($details?->gstin)<div>GSTIN: {{ $details->gstin }}</div>@endif
    </td></tr>
</table>

<table class="grid" style="margin-bottom: 6pt">
    <thead><tr><th>#</th><th>Item</th><th>Godown</th><th class="num">Quantity</th><th>Order</th></tr></thead>
    <tbody>
    @foreach ($note->items as $line)
        <tr>
            <td>{{ $line->line_no }}</td>
            <td>{{ $line->description }}@if ($line->batch_no)<br><small>Batch {{ $line->batch_no }}@if ($line->expiry_date) · exp {{ $line->expiry_date->format('d-m-Y') }}@endif</small>@endif @if ($line->serials)<br><small>S/N: {{ implode(', ', $line->serialList()) }}</small>@endif</td>
            <td>{{ $line->godown->name }}</td>
            <td class="num">{{ Decimal::trim($line->quantity) }} {{ $line->unit }}</td>
            <td>{{ $line->orderItem?->order?->number }}</td>
        </tr>
    @endforeach
    </tbody>
</table>
@if ($note->narration)<div class="small">{{ $note->narration }}</div>@endif
<table style="margin-top: 30pt"><tr>
    <td>{{ $config['direction'] === 'out' ? 'Received the above goods in good condition' : 'Checked and received by' }}<br><br><br>Signature</td>
    <td class="right">For {{ $company->displayName() }}<br><br><br>Authorised signatory</td>
</tr></table>
@endsection
