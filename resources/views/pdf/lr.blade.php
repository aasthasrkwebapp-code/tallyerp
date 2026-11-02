@php
    use App\Support\Decimal;
    use App\Support\Money;
    $consignor = $trip->consignor();
    $lrNo = $trip->lr_number ?: $trip->number;
@endphp
@extends('pdf.layout', ['footerLeft' => 'LR '.$lrNo.' · '.$company->displayName()])
@section('title', 'Lorry Receipt '.$lrNo)

@section('content')
@if ($printable ?? false)
    <div style="margin-bottom: 8pt" class="no-print">
        <button onclick="window.print()">Print</button>
        <a href="{{ request()->fullUrlWithQuery(['format' => 'pdf']) }}">Download PDF</a>
        <a href="{{ route('trips.show', $trip) }}">Back to trip</a>
    </div>
    <style>@media print { .no-print { display: none } } .footer { display: none } body { max-width: 190mm; margin: 0 auto; } @media screen { .lr-copy { margin-bottom: 18pt; padding-bottom: 18pt; border-bottom: 1px dashed #999 } }</style>
@endif
@foreach ($copies as $copy)
<div class="lr-copy" style="{{ $loop->last ? '' : 'page-break-after: always;' }}">
    <table style="margin-bottom: 6pt">
        <tr>
            <td style="width: 60%">
                <h1>{{ $company->displayName() }}</h1>
                <div class="pre">{{ $company->address }}</div>
                <div>{{ trim(implode(' ', array_filter([$company->city, $company->pincode]))) }} {{ $company->state }}</div>
                @if ($company->gstin)<div>GSTIN: <b>{{ $company->gstin }}</b></div>@endif
                @if ($company->phone ?? null)<div>Phone: {{ $company->phone }}</div>@endif
            </td>
            <td class="right" style="width: 40%">
                <div class="bold upper" style="font-size: 12pt">Lorry receipt</div>
                <div class="small muted">Consignment note</div>
                <div class="bold" style="margin-top: 3pt">{{ strtoupper($copy) }}</div>
                <div>LR No. <b>{{ $lrNo }}</b></div>
                <div>Date {{ ($trip->lr_date ?? $trip->start_date)->format('d-m-Y') }}</div>
            </td>
        </tr>
    </table>

    <table class="grid" style="margin-bottom: 6pt">
        <tr>
            <td style="width: 50%">
                <div class="muted small">Consignor</div>
                <div class="bold">{{ $consignor->name ?: '—' }}</div>
                <div class="pre">{{ $consignor->address }}</div>
                @if ($consignor->gstin)<div>GSTIN: {{ $consignor->gstin }}</div>@endif
            </td>
            <td style="width: 50%">
                <div class="muted small">Consignee</div>
                <div class="bold">{{ $trip->consignee_name ?: '—' }}</div>
                <div class="pre">{{ $trip->consignee_address }}</div>
                @if ($trip->consignee_gstin)<div>GSTIN: {{ $trip->consignee_gstin }}</div>@endif
            </td>
        </tr>
        <tr>
            <td>From: <b>{{ $trip->origin }}</b></td>
            <td>To: <b>{{ $trip->destination }}</b></td>
        </tr>
        <tr>
            <td>Vehicle: <b>{{ $trip->vehicle->number }}</b>@if ($trip->driver) · Driver: {{ $trip->driver->name }}@if ($trip->driver->phone) ({{ $trip->driver->phone }})@endif @endif</td>
            <td>E-way bill: <b>{{ $trip->eway_bill_no ? trim(chunk_split($trip->eway_bill_no, 4, ' ')) : '—' }}</b>@if ($trip->eway_bill_valid_till) · valid till {{ $trip->eway_bill_valid_till->format('d-m-Y') }}@endif</td>
        </tr>
    </table>

    <table class="grid" style="margin-bottom: 6pt">
        <thead><tr><th class="num">Packages</th><th>Said to contain</th><th class="num">Weight</th><th>Invoice no.</th><th class="num">Value of goods</th></tr></thead>
        <tbody>
            <tr>
                <td class="num">{{ $trip->packages ?? '—' }}{{ $trip->packing ? ' '.$trip->packing : '' }}</td>
                <td>{{ $trip->material ?: '—' }}</td>
                <td class="num">{{ $trip->weight_tonnes !== null ? Decimal::trim($trip->weight_tonnes).' t' : '—' }}</td>
                <td>{{ $trip->goods_invoice_no ?: '—' }}</td>
                <td class="num">{{ $trip->goods_value !== null ? Money::format($trip->goods_value) : '—' }}</td>
            </tr>
        </tbody>
    </table>

    <table class="grid" style="margin-bottom: 6pt">
        <tr>
            <td style="width: 50%">Freight: <b>{{ (float) $trip->freight_amount > 0 ? Money::format($trip->freight_amount) : '—' }}</b></td>
            <td style="width: 50%">Freight terms: <b>{{ \App\Models\Trip::FREIGHT_TERMS[$trip->freight_terms] ?? 'To be billed' }}</b>@if ($trip->freight_terms === 'to_be_billed' && $trip->party) · billed to {{ $trip->party->name }}@endif</td>
        </tr>
    </table>
    @if ($trip->narration)<div class="small" style="margin-bottom: 6pt">{{ $trip->narration }}</div>@endif

    <div class="small muted">Goods are carried at owner's risk unless insured. Received the goods in apparent good order; the contents, weight and value are as declared by the consignor and not checked.</div>
    <table style="margin-top: 30pt"><tr>
        <td>Consignor's signature</td>
        <td class="center">Received by consignee<br><span class="small muted">(name, date and seal)</span></td>
        <td class="right">For {{ $company->displayName() }}<br><br><br>Booking clerk</td>
    </tr></table>
</div>
@endforeach
@endsection
