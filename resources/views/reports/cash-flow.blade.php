@extends('layouts.erp')

@section('title', 'Cash Flow')

@php($m = fn ($p) => \App\Support\Money::format($p, paiseInput: true))
@php($signed = fn ($p) => $p < 0 ? '('.$m(-$p).')' : $m($p))
@php($expand = request()->boolean('expand'))

@section('content')
<div class="page-header">
    <div>
        <h1>Cash flow statement</h1>
        <p class="text-secondary mb-0">{{ \Illuminate\Support\Carbon::parse($from)->format('d M Y') }} to {{ \Illuminate\Support\Carbon::parse($to)->format('d M Y') }} · direct method, cash and bank ledgers</p>
    </div>
    <a href="{{ request()->fullUrlWithQuery(['expand' => $expand ? 0 : 1]) }}" class="btn btn-outline-secondary no-print">{{ $expand ? 'Condensed' : 'Detailed' }}</a>
</div>

@include('reports._period', ['csv' => true])

@if ($opening + $net !== $closing)
    <div class="alert alert-danger">The cash flow does not agree with the cash and bank balances (difference {{ $m($closing - $opening - $net) }}).</div>
@endif

<div class="erp-card">
    <div class="table-responsive">
        <table class="table table-sm table-report mb-0">
            <thead class="table-light"><tr><th>Particulars</th><th class="text-end" style="width: 22%"></th><th class="text-end" style="width: 22%">Amount</th></tr></thead>
            <tbody>
                <tr class="row-group"><td>Opening cash and bank balance</td><td></td><td class="amount">{{ $signed($opening) }}</td></tr>
                @foreach ($sections as $key => $section)
                    <tr class="row-group"><td colspan="3">{{ $section['label'] }}</td></tr>
                    @forelse ($section['groups'] as $node)
                        <tr>
                            <td class="indent-1"><a href="{{ route('reports.group-summary', ['group' => $node->group->id, 'from' => $from, 'to' => $to]) }}" class="text-body">{{ $node->group->name }}</a></td>
                            <td class="amount">{{ $signed($node->amount) }}</td><td></td>
                        </tr>
                        @if ($expand)
                            @foreach ($node->ledgers as $l)
                                @continue($l->amount === 0)
                                <tr class="text-secondary">
                                    <td class="indent-2"><a href="{{ route('reports.ledger', ['ledger' => $l->account->id, 'from' => $from, 'to' => $to]) }}" class="text-secondary">{{ $l->account->name }}</a></td>
                                    <td class="amount">{{ $signed($l->amount) }}</td><td></td>
                                </tr>
                            @endforeach
                        @endif
                    @empty
                        <tr><td class="indent-1 text-secondary" colspan="3">None</td></tr>
                    @endforelse
                    <tr>
                        <td class="indent-1 fw-semibold">Net cash {{ $section['total'] >= 0 ? 'from' : 'used in' }} {{ $key }} activities
                            <small class="text-secondary fw-normal d-block d-md-inline">(in {{ $m($section['inflow']) }}, out {{ $m($section['outflow']) }})</small></td>
                        <td></td><td class="amount fw-semibold">{{ $signed($section['total']) }}</td>
                    </tr>
                @endforeach
                <tr class="row-total"><td>Net {{ $net >= 0 ? 'increase' : 'decrease' }} in cash and bank</td><td></td><td class="amount">{{ $signed($net) }}</td></tr>
            </tbody>
            <tfoot>
                <tr class="row-total"><td>Closing cash and bank balance</td><td></td><td class="amount">{{ $signed($closing) }}</td></tr>
            </tfoot>
        </table>
    </div>
</div>

@if ($cashAccounts->count() > 1)
    <div class="erp-card mt-3">
        <div class="erp-card-header">Cash and bank ledgers</div>
        <div class="table-responsive">
            <table class="table table-sm mb-0 table-stack">
                <thead class="table-light"><tr><th>Ledger</th><th class="text-end">Opening</th><th class="text-end">Closing</th></tr></thead>
                <tbody>
                @foreach ($cashAccounts as $b)
                    <tr>
                        <td data-label="Ledger"><a href="{{ route('reports.ledger', ['ledger' => $b->account->id, 'from' => $from, 'to' => $to]) }}">{{ $b->account->name }}</a></td>
                        <td data-label="Opening" class="amount">{{ \App\Support\Money::drCr($b->opening) }}</td>
                        <td data-label="Closing" class="amount">{{ \App\Support\Money::drCr($b->closing) }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </div>
@endif
<p class="small text-secondary mt-2">Amounts in brackets are cash paid out. Each voucher line against a cash or bank ledger is classified by its ledger's group: Fixed Assets and Investments are investing; Capital Account and Loans (Liability) are financing; all others are operating. Transfers between cash and bank ledgers are left out.</p>
@endsection
