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
                        <td colspan="2">Net tax payable / (ITC carried forward)</td>
                        @foreach (['igst', 'cgst', 'sgst'] as $head)
                            <td class="text-end {{ $payable->$head < 0 ? 'text-success' : '' }}">
                                {{ $payable->$head < 0 ? '('.$m(-$payable->$head).')' : $m($payable->$head) }}
                            </td>
                        @endforeach
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
    <p class="small text-secondary mt-2">
        Simple head-wise set-off. Cross-utilisation of IGST credit against CGST/SGST is not applied here.
    </p>
</div>
@endsection
