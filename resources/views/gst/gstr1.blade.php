@extends('layouts.erp')

@section('title', 'GSTR-1')

@section('content')
@php
    $m = fn ($v) => number_format((float) $v, 2);
    $taxCells = fn ($r) => '<td class="text-end">'.$m($r->taxable).'</td><td class="text-end">'.$m($r->igst).'</td><td class="text-end">'.$m($r->cgst).'</td><td class="text-end">'.$m($r->sgst).'</td>';
@endphp
<div class="container-fluid py-3">
    @include('reports._header', ['title' => 'GSTR-1 (Outward supplies)', 'subtitle' => $from->format('d-m-Y').' to '.$to->format('d-m-Y')])
    @include('gst._period')

    <div class="alert alert-light border">
        Total taxable value <strong>{{ $m($total->taxable) }}</strong> · Total tax <strong>{{ $m($total->tax) }}</strong>
    </div>

    <h3 class="h6 mt-4">B2B invoices (registered buyers)</h3>
    <div class="card border-0 shadow-sm mb-4">
        <div class="table-responsive">
            <table class="table align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>GSTIN</th><th>Party</th><th>Invoice</th><th>Date</th><th>POS</th>
                        <th class="text-end">Invoice value</th>
                        <th class="text-end">Taxable</th><th class="text-end">IGST</th><th class="text-end">CGST</th><th class="text-end">SGST</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($b2b as $row)
                        <tr>
                            <td>{{ $row->gstin }}</td>
                            <td>{{ $row->party }}</td>
                            <td><a href="{{ route('companies.gst-invoices.show', [$company, $row->voucher]) }}">#{{ $row->voucher->number }}</a></td>
                            <td>{{ $row->voucher->date->format('d-m-Y') }}</td>
                            <td>{{ $row->voucher->place_of_supply }}</td>
                            <td class="text-end">{{ $m($row->value) }}</td>
                            {!! $taxCells($row) !!}
                        </tr>
                    @empty
                        <tr><td colspan="10" class="text-center text-secondary py-3">No B2B invoices.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <h3 class="h6">B2C (unregistered buyers) by place of supply and rate</h3>
    <div class="card border-0 shadow-sm mb-4">
        <div class="table-responsive">
            <table class="table align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Place of supply</th><th class="text-end">Rate</th>
                        <th class="text-end">Taxable</th><th class="text-end">IGST</th><th class="text-end">CGST</th><th class="text-end">SGST</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($b2c as $row)
                        <tr>
                            <td>{{ $row->place_of_supply }} - {{ \App\Support\GstStates::name($row->place_of_supply) }}</td>
                            <td class="text-end">{{ $row->rate }}%</td>
                            {!! $taxCells($row) !!}
                        </tr>
                    @empty
                        <tr><td colspan="6" class="text-center text-secondary py-3">No B2C supplies.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <h3 class="h6">HSN / SAC summary</h3>
    <div class="card border-0 shadow-sm">
        <div class="table-responsive">
            <table class="table align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>HSN/SAC</th><th class="text-end">Rate</th>
                        <th class="text-end">Taxable</th><th class="text-end">IGST</th><th class="text-end">CGST</th><th class="text-end">SGST</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($hsn as $row)
                        <tr>
                            <td>{{ $row->hsn_sac }}</td>
                            <td class="text-end">{{ $row->rate }}%</td>
                            {!! $taxCells($row) !!}
                        </tr>
                    @empty
                        <tr><td colspan="6" class="text-center text-secondary py-3">Nothing to summarise.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
