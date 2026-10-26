@extends('layouts.erp')

@section('title', 'Profit & Loss')

@php($m = fn ($p) => \App\Support\Money::format($p, paiseInput: true))
@php($expand = request()->boolean('expand'))

@section('content')
<div class="page-header">
    <div>
        <h1>Profit &amp; loss account</h1>
        <p class="text-secondary mb-0">{{ \Illuminate\Support\Carbon::parse($from)->format('d M Y') }} to {{ \Illuminate\Support\Carbon::parse($to)->format('d M Y') }}</p>
    </div>
    <a href="{{ request()->fullUrlWithQuery(['expand' => $expand ? 0 : 1]) }}" class="btn btn-outline-secondary no-print">{{ $expand ? 'Condensed' : 'Detailed' }}</a>
</div>

@include('reports._period')

@php($block = function ($title, $nodes) { return compact('title', 'nodes'); })
<div class="erp-card">
    <div class="table-responsive">
        <table class="table table-sm table-report mb-0">
            <thead class="table-light"><tr><th>Particulars</th><th class="text-end" style="width: 22%"></th><th class="text-end" style="width: 22%">Amount</th></tr></thead>
            <tbody>
            @foreach ([
                ['Trading income', 'trading_income', 1],
                ['Less: direct costs', 'trading_expense', -1],
            ] as [$title, $key, $sign])
                <tr class="row-group"><td colspan="3">{{ $title }}</td></tr>
                @foreach ($sections[$key] as $node)
                    <tr>
                        <td class="indent-1"><a href="{{ route('reports.group-summary', ['group' => $node->group->id, 'from' => $from, 'to' => $to]) }}" class="text-body">{{ $node->group->name }}</a></td>
                        <td></td>
                        <td class="amount">{{ $m($node->amount) }}</td>
                    </tr>
                    @include('reports._statement-rows', ['items' => $node->children, 'depth' => 2, 'expand' => $expand])
                @endforeach
                @if ($sections[$key]->isEmpty())<tr><td class="indent-1 text-secondary" colspan="3">None</td></tr>@endif
            @endforeach
            <tr class="row-total"><td>{{ $gross_profit >= 0 ? 'Gross profit' : 'Gross loss' }}</td><td></td><td class="amount">{{ $m(abs($gross_profit)) }}</td></tr>

            @foreach ([
                ['Add: indirect income', 'other_income'],
                ['Less: indirect expenses', 'other_expense'],
            ] as [$title, $key])
                <tr class="row-group"><td colspan="3">{{ $title }}</td></tr>
                @foreach ($sections[$key] as $node)
                    <tr>
                        <td class="indent-1"><a href="{{ route('reports.group-summary', ['group' => $node->group->id, 'from' => $from, 'to' => $to]) }}" class="text-body">{{ $node->group->name }}</a></td>
                        <td></td>
                        <td class="amount">{{ $m($node->amount) }}</td>
                    </tr>
                    @include('reports._statement-rows', ['items' => $node->children, 'depth' => 2, 'expand' => $expand])
                @endforeach
                @if ($sections[$key]->isEmpty())<tr><td class="indent-1 text-secondary" colspan="3">None</td></tr>@endif
            @endforeach
            </tbody>
            <tfoot>
                <tr class="row-total {{ $net_profit >= 0 ? 'text-success' : 'text-danger' }}">
                    <td>{{ $net_profit >= 0 ? 'Net profit' : 'Net loss' }}</td><td></td><td class="amount">{{ $m(abs($net_profit)) }}</td>
                </tr>
            </tfoot>
        </table>
    </div>
</div>
<p class="small text-secondary mt-2">Closing stock is not yet valued automatically. Until the inventory module is used, record closing stock with a journal to a Stock-in-Hand ledger.</p>
@endsection
