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
                        {{-- Shown before the rule 42 reversal; $itc itself is net of it. --}}
                        <td class="text-end">{{ $m($itc->igst + $reversal->igst) }}</td>
                        <td class="text-end">{{ $m($itc->cgst + $reversal->cgst) }}</td>
                        <td class="text-end">{{ $m($itc->sgst + $reversal->sgst) }}</td>
                    </tr>
                    @if ($reversal->igst + $reversal->cgst + $reversal->sgst != 0)
                        <tr class="text-danger">
                            <td>4(B)(1) Reversal of common credit, rule 42 <span class="small">({{ $reversal->ratio }}% exempt sales)</span></td>
                            <td></td>
                            <td class="text-end">({{ $m($reversal->igst) }})</td>
                            <td class="text-end">({{ $m($reversal->cgst) }})</td>
                            <td class="text-end">({{ $m($reversal->sgst) }})</td>
                        </tr>
                        <tr class="fw-semibold">
                            <td>4(C) Net ITC available</td>
                            <td></td>
                            <td class="text-end">{{ $m($itc->igst) }}</td>
                            <td class="text-end">{{ $m($itc->cgst) }}</td>
                            <td class="text-end">{{ $m($itc->sgst) }}</td>
                        </tr>
                    @endif
                    @if ($ineligible->tax != 0)
                        <tr class="text-secondary">
                            <td>4(D) Ineligible ITC (blocked, added to cost)</td>
                            <td class="text-end">{{ $m($ineligible->taxable) }}</td>
                            <td class="text-end">{{ $m($ineligible->igst) }}</td>
                            <td class="text-end">{{ $m($ineligible->cgst) }}</td>
                            <td class="text-end">{{ $m($ineligible->sgst) }}</td>
                        </tr>
                    @endif
                    <tr class="text-secondary">
                        <td>Unused credit brought forward from earlier months</td>
                        <td></td>
                        <td class="text-end">{{ $m($openingCredit->igst) }}</td>
                        <td class="text-end">{{ $m($openingCredit->cgst) }}</td>
                        <td class="text-end">{{ $m($openingCredit->sgst) }}</td>
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
