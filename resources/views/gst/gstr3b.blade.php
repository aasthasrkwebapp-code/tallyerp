@extends('layouts.erp')

@section('title', 'GSTR-3B')

@section('content')
@php
    $m = fn ($v) => inr((float) $v, 2);
@endphp
<div class="container-fluid py-3">
    @include('reports._header', ['title' => 'GSTR-3B (Summary)', 'subtitle' => $from->format('d-m-Y').' to '.$to->format('d-m-Y')])
    @include('gst._period')

    <div class="card border-0 shadow-sm">
        <div class="table-responsive">
            <table class="table align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Particulars</th>
                        <th class="text-end">Taxable value</th>
                        <th class="text-end">IGST</th>
                        <th class="text-end">CGST</th>
                        <th class="text-end">SGST</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>3.1(a) Outward taxable supplies</td>
                        <td class="text-end">{{ $m($outward->taxable) }}</td>
                        <td class="text-end">{{ $m($outward->igst) }}</td>
                        <td class="text-end">{{ $m($outward->cgst) }}</td>
                        <td class="text-end">{{ $m($outward->sgst) }}</td>
                    </tr>
                    <tr>
                        <td>4(A)(5) Eligible ITC on inward supplies</td>
                        <td class="text-end">{{ $m($itc->taxable) }}</td>
                        <td class="text-end">{{ $m($itc->igst) }}</td>
                        <td class="text-end">{{ $m($itc->cgst) }}</td>
                        <td class="text-end">{{ $m($itc->sgst) }}</td>
                    </tr>
                </tbody>
                <tfoot class="fw-semibold">
                    <tr>
                        <td colspan="2">Tax to pay in cash</td>
                        @foreach (['igst', 'cgst', 'sgst'] as $head)
                            <td class="text-end">{{ $m($payable->$head) }}</td>
                        @endforeach
                    </tr>
                    <tr class="text-success">
                        <td colspan="2">Credit carried forward</td>
                        @foreach (['igst', 'cgst', 'sgst'] as $head)
                            <td class="text-end">{{ $m($carryForward->$head) }}</td>
                        @endforeach
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>

    <div class="card border-0 shadow-sm mt-3">
        <div class="card-header bg-white fw-semibold">6.1 How input credit was used</div>
        <div class="table-responsive">
            <table class="table align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Credit of</th>
                        <th class="text-end">Against IGST</th>
                        <th class="text-end">Against CGST</th>
                        <th class="text-end">Against SGST</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach (['igst' => 'IGST', 'cgst' => 'CGST', 'sgst' => 'SGST'] as $from => $label)
                        <tr>
                            <td>{{ $label }}</td>
                            @foreach (['igst', 'cgst', 'sgst'] as $to)
                                <td class="text-end">{{ ($from === 'cgst' && $to === 'sgst') || ($from === 'sgst' && $to === 'cgst') ? '—' : $m($used[$from][$to]) }}</td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
    <p class="small text-secondary mt-2">
        IGST credit is used first (IGST, then CGST, then SGST). CGST credit then covers CGST and IGST, and
        SGST credit covers SGST and IGST. CGST and SGST credit can never be used against each other.
    </p>
</div>
@endsection
