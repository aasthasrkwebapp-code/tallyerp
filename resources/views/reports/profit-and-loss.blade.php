@extends('layouts.erp')

@section('title', 'Profit & Loss')

@section('content')
@php
    $tradingLeft = $tradingExpense->sum('total') + max($grossProfit, 0);
    $tradingRight = $tradingIncome->sum('total') + max(-$grossProfit, 0);
    $plLeft = $indirectExpense->sum('total') + max(-$grossProfit, 0) + max($netProfit, 0);
    $plRight = $indirectIncome->sum('total') + max($grossProfit, 0) + max(-$netProfit, 0);
@endphp
<div class="container-fluid py-3">
    @include('reports._header', ['title' => 'Profit & Loss A/c', 'subtitle' => 'Up to '.$to->format('d-m-Y')])
    @include('reports._as-on')

    <div class="alert {{ $netProfit >= 0 ? 'alert-success' : 'alert-danger' }}">
        Net {{ $netProfit >= 0 ? 'Profit' : 'Loss' }}: <strong>{{ number_format(abs($netProfit), 2) }}</strong>
        <span class="text-secondary">· Gross {{ $grossProfit >= 0 ? 'profit' : 'loss' }} {{ number_format(abs($grossProfit), 2) }}</span>
    </div>

    <div class="row g-3">
        <div class="col-12 col-lg-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white fw-semibold">Expenses</div>
                <table class="table mb-0">
                    <tbody>
                        @include('reports._section', ['sections' => $tradingExpense])
                        @if ($grossProfit > 0)
                            <tr><td class="fw-semibold">Gross Profit c/o</td><td class="text-end fw-semibold">{{ number_format($grossProfit, 2) }}</td></tr>
                        @endif
                        <tr class="table-light fw-semibold"><td></td><td class="text-end">{{ number_format($tradingLeft, 2) }}</td></tr>

                        @if ($grossProfit < 0)
                            <tr><td class="fw-semibold">Gross Loss b/f</td><td class="text-end fw-semibold">{{ number_format(-$grossProfit, 2) }}</td></tr>
                        @endif
                        @include('reports._section', ['sections' => $indirectExpense])
                        @if ($netProfit > 0)
                            <tr><td class="fw-semibold">Net Profit</td><td class="text-end fw-semibold">{{ number_format($netProfit, 2) }}</td></tr>
                        @endif
                    </tbody>
                    <tfoot class="fw-semibold">
                        <tr><td>Total</td><td class="text-end">{{ number_format($plLeft, 2) }}</td></tr>
                    </tfoot>
                </table>
            </div>
        </div>
        <div class="col-12 col-lg-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white fw-semibold">Income</div>
                <table class="table mb-0">
                    <tbody>
                        @include('reports._section', ['sections' => $tradingIncome])
                        @if ($grossProfit < 0)
                            <tr><td class="fw-semibold">Gross Loss c/o</td><td class="text-end fw-semibold">{{ number_format(-$grossProfit, 2) }}</td></tr>
                        @endif
                        <tr class="table-light fw-semibold"><td></td><td class="text-end">{{ number_format($tradingRight, 2) }}</td></tr>

                        @if ($grossProfit > 0)
                            <tr><td class="fw-semibold">Gross Profit b/f</td><td class="text-end fw-semibold">{{ number_format($grossProfit, 2) }}</td></tr>
                        @endif
                        @include('reports._section', ['sections' => $indirectIncome])
                        @if ($netProfit < 0)
                            <tr><td class="fw-semibold">Net Loss</td><td class="text-end fw-semibold">{{ number_format(-$netProfit, 2) }}</td></tr>
                        @endif
                    </tbody>
                    <tfoot class="fw-semibold">
                        <tr><td>Total</td><td class="text-end">{{ number_format($plRight, 2) }}</td></tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>
</div>
@endsection
