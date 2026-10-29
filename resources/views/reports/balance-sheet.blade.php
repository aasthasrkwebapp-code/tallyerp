@extends('layouts.erp')

@section('title', 'Balance Sheet')

@php($m = fn ($p) => \App\Support\Money::format($p, paiseInput: true))
@php($expand = request()->boolean('expand'))
@php($from = $yearStart)

@section('content')
<div class="page-header">
    <div>
        <h1>Balance sheet</h1>
        <p class="text-secondary mb-0">As on {{ \Illuminate\Support\Carbon::parse($to)->format('d M Y') }}</p>
    </div>
    <a href="{{ request()->fullUrlWithQuery(['expand' => $expand ? 0 : 1]) }}" class="btn btn-outline-secondary no-print">{{ $expand ? 'Condensed' : 'Detailed' }}</a>
</div>

@include('reports._period', ['single' => true, 'csv' => true])

@if ($totalAssets !== $totalLiabilities)
    <div class="alert alert-danger">Assets and liabilities do not agree (difference {{ $m($totalAssets - $totalLiabilities) }}).</div>
@endif

<div class="row g-3">
    @foreach ([['Liabilities', $liabilities, true], ['Assets', $assets, false]] as [$title, $nodes, $isLiability])
        <div class="col-12 col-xl-6">
            <div class="erp-card h-100">
                <div class="table-responsive">
                    <table class="table table-sm table-report mb-0">
                        <thead class="table-light"><tr><th>{{ $title }}</th><th class="text-end" style="width: 25%"></th><th class="text-end" style="width: 25%">Amount</th></tr></thead>
                        <tbody>
                        @foreach ($nodes as $node)
                            <tr class="row-group">
                                <td><a href="{{ route('reports.group-summary', ['group' => $node->group->id, 'from' => $from, 'to' => $to]) }}" class="text-body">{{ $node->group->name }}</a></td>
                                <td></td>
                                <td class="amount">{{ $m($node->amount) }}</td>
                            </tr>
                            @include('reports._statement-rows', ['items' => $node->children, 'depth' => 1, 'expand' => $expand])
                        @endforeach
                        @if (! $isLiability && $closingStock)
                            <tr class="row-group">
                                <td>@can('inventory.view')<a href="{{ route('stock-summary', ['to' => $to]) }}" class="text-body">Closing stock</a>@else Closing stock @endcan</td>
                                <td></td>
                                <td class="amount">{{ $m($closingStock) }}</td>
                            </tr>
                        @endif
                        @if ($isLiability)
                            @if ($accumulatedProfit || $currentProfit)
                                <tr class="row-group"><td>Profit &amp; Loss A/c</td><td></td><td class="amount">{{ $m($accumulatedProfit + $currentProfit) }}</td></tr>
                                @if ($accumulatedProfit)<tr><td class="indent-1">Opening balance (previous years)</td><td class="amount text-secondary">{{ $m($accumulatedProfit) }}</td><td></td></tr>@endif
                                <tr><td class="indent-1"><a href="{{ route('reports.profit-loss', ['from' => $from, 'to' => $to]) }}">Current period</a></td><td class="amount text-secondary">{{ $m($currentProfit) }}</td><td></td></tr>
                            @endif
                            @if ($difference)
                                <tr class="row-group text-danger"><td>Difference in opening balances</td><td></td><td class="amount">{{ $m($difference) }}</td></tr>
                            @endif
                        @endif
                        </tbody>
                        <tfoot>
                            <tr class="row-total"><td>Total</td><td></td><td class="amount">{{ $m($isLiability ? $totalLiabilities : $totalAssets) }}</td></tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>
    @endforeach
</div>
@endsection
