@extends('layouts.erp')

@section('title', 'Funds Flow')

@php($m = fn ($p) => \App\Support\Money::format($p, paiseInput: true))
@php($signed = fn ($p) => $p < 0 ? '('.$m(-$p).')' : $m($p))
@php($expand = request()->boolean('expand'))
@php($wc = $working_capital)

@section('content')
<div class="page-header">
    <div>
        <h1>Funds flow statement</h1>
        <p class="text-secondary mb-0">{{ \Illuminate\Support\Carbon::parse($from)->format('d M Y') }} to {{ \Illuminate\Support\Carbon::parse($to)->format('d M Y') }} · long-term sources and uses of funds, and the change in working capital</p>
    </div>
    <a href="{{ request()->fullUrlWithQuery(['expand' => $expand ? 0 : 1]) }}" class="btn btn-outline-secondary no-print">{{ $expand ? 'Condensed' : 'Detailed' }}</a>
</div>

@include('reports._period', ['csv' => true])

@if ($difference !== 0)
    <div class="alert alert-danger">Sources less applications does not agree with the change in working capital (difference {{ $signed($difference) }}). Check the integrity report for unbalanced vouchers or opening balances.</div>
@endif

<div class="row g-3">
    @foreach ([['Sources of funds', $sources, 'source'], ['Application of funds', $applications, 'application']] as [$title, $items, $kind])
        <div class="col-12 col-lg-6">
            <div class="erp-card h-100">
                <div class="table-responsive">
                    <table class="table table-sm table-report mb-0">
                        <thead class="table-light"><tr><th>{{ $title }}</th><th class="text-end" style="width: 32%">Amount</th></tr></thead>
                        <tbody>
                        @foreach ($items as $item)
                            @php($verb = ($item->group->nature === 'asset') === ($kind === 'source') ? 'Decrease in' : 'Increase in')
                            <tr>
                                <td><span class="text-secondary small">{{ $verb }}</span> <a href="{{ route('reports.group-summary', ['group' => $item->group->id, 'from' => $from, 'to' => $to]) }}" class="text-body">{{ $item->group->name }}</a></td>
                                <td class="amount">{{ $m($item->amount) }}</td>
                            </tr>
                            @if ($expand)
                                @foreach ($item->ledgers as $l)
                                    <tr class="text-secondary">
                                        <td class="indent-2"><a href="{{ route('reports.ledger', ['ledger' => $l->account->id, 'from' => $from, 'to' => $to]) }}">{{ $l->account->name }}</a></td>
                                        <td class="amount small">{{ $signed($kind === 'application' ? $l->change : -$l->change) }}</td>
                                    </tr>
                                @endforeach
                            @endif
                        @endforeach
                        @if ($kind === 'source' && $net_profit > 0)
                            <tr><td>Net profit</td><td class="amount">{{ $m($net_profit) }}</td></tr>
                        @elseif ($kind === 'application' && $net_profit < 0)
                            <tr><td>Net loss</td><td class="amount">{{ $m(-$net_profit) }}</td></tr>
                        @endif
                        @if ($items->isEmpty() && ! (($kind === 'source' && $net_profit > 0) || ($kind === 'application' && $net_profit < 0)))
                            <tr><td colspan="2" class="text-secondary">None</td></tr>
                        @endif
                        </tbody>
                        <tfoot><tr class="row-total"><td>Total</td><td class="amount">{{ $m($kind === 'source' ? $total_sources : $total_applications) }}</td></tr></tfoot>
                    </table>
                </div>
            </div>
        </div>
    @endforeach
</div>

<div class="erp-card p-3 my-3 d-flex flex-wrap justify-content-between gap-2 fw-semibold">
    <span>{{ $wc['change'] >= 0 ? 'Increase' : 'Decrease' }} in working capital (sources − applications)</span>
    <span class="amount {{ $wc['change'] >= 0 ? 'text-success' : 'text-danger' }}">{{ $m(abs($total_sources - $total_applications)) }}</span>
</div>

<div class="erp-card">
    <div class="p-3 pb-0"><h2 class="h6 mb-2">Changes in working capital</h2></div>
    <div class="table-responsive">
        <table class="table table-sm table-report mb-0 table-stack">
            <thead class="table-light"><tr><th>Current assets and liabilities</th><th class="text-end">Opening</th><th class="text-end">Closing</th><th class="text-end">Increase / (decrease) in working capital</th></tr></thead>
            <tbody>
            @forelse ($wc['rows'] as $r)
                @php($show = fn ($p) => $r->side === 'liability' ? $m(abs($p)).($p > 0 ? ' Dr' : ($p < 0 ? ' Cr' : '')) : $signed($p))
                <tr>
                    <td data-label="Particulars">
                        @if ($r->group_id)<a href="{{ route('reports.group-summary', ['group' => $r->group_id, 'from' => $from, 'to' => $to]) }}" class="text-body">{{ $r->label }}</a>
                        @elseif ($r->account_id)<a href="{{ route('reports.ledger', ['ledger' => $r->account_id, 'from' => $from, 'to' => $to]) }}">{{ $r->label }}</a>
                        @else{{ $r->label }}@endif
                        <span class="small text-secondary">({{ $r->side === 'asset' ? 'current asset' : 'current liability' }})</span>
                    </td>
                    <td data-label="Opening" class="amount">{{ $show($r->opening) }}</td>
                    <td data-label="Closing" class="amount">{{ $show($r->closing) }}</td>
                    <td data-label="Effect" class="amount fw-semibold">{{ $signed($r->change) }}</td>
                </tr>
            @empty
                <tr><td colspan="4" class="text-secondary">No current assets or liabilities.</td></tr>
            @endforelse
            </tbody>
            <tfoot>
                <tr class="row-total"><td data-label="">Working capital</td><td class="amount" data-label="Opening">{{ $signed($wc['opening']) }}</td><td class="amount" data-label="Closing">{{ $signed($wc['closing']) }}</td><td class="amount" data-label="Change">{{ $signed($wc['change']) }}</td></tr>
            </tfoot>
        </table>
    </div>
</div>
<p class="small text-secondary mt-2">Working capital is current assets, including closing stock, less current liabilities. All other balance sheet groups are long-term: a fall in a long-term asset or a rise in a long-term liability brings funds in. Amounts in brackets are decreases.</p>
@endsection
