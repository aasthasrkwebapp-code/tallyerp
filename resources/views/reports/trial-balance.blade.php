@extends('layouts.erp')

@section('title', 'Trial Balance')

@php($m = fn ($p) => $p ? \App\Support\Money::format($p, paiseInput: true) : '')
@php($dc = fn ($p) => $p ? \App\Support\Money::drCr($p) : '')

@section('content')
<div class="page-header">
    <div>
        <h1>Trial balance</h1>
        <p class="text-secondary mb-0">{{ \Illuminate\Support\Carbon::parse($from)->format('d M Y') }} to {{ \Illuminate\Support\Carbon::parse($to)->format('d M Y') }}</p>
    </div>
    <a href="{{ request()->fullUrlWithQuery(['ledgers' => $detailed ? 0 : 1]) }}" class="btn btn-outline-secondary no-print">{{ $detailed ? 'Group-wise' : 'Show ledgers' }}</a>
</div>

@include('reports._period', ['csv' => true])

@if ($totals['closing_dr'] !== $totals['closing_cr'] || $totals['debit'] !== $totals['credit'])
    <div class="alert alert-danger">The trial balance does not agree. Run the data integrity check.</div>
@endif

<div class="erp-card">
    <div class="table-responsive">
        <table class="table table-sm table-report mb-0">
            <thead class="table-light">
                <tr><th>Particulars</th><th class="text-end">Opening</th><th class="text-end">Debit</th><th class="text-end">Credit</th><th class="text-end">Closing Dr</th><th class="text-end">Closing Cr</th></tr>
            </thead>
            <tbody>
            @foreach ($tree as $node)
                @continue(! $detailed && $node->depth > 0)
                @continue(! $node->opening && ! $node->debit && ! $node->credit && ! $node->closing)
                <tr class="{{ $node->depth === 0 ? 'row-group' : '' }}">
                    <td class="indent-{{ min($node->depth, 3) }}"><a href="{{ route('reports.group-summary', ['group' => $node->group->id, 'from' => $from, 'to' => $to]) }}" class="text-body">{{ $node->group->name }}</a></td>
                    <td class="amount">{{ $dc($node->opening) }}</td>
                    <td class="amount">{{ $m($node->debit) }}</td>
                    <td class="amount">{{ $m($node->credit) }}</td>
                    <td class="amount">{{ $node->closing > 0 ? $m($node->closing) : '' }}</td>
                    <td class="amount">{{ $node->closing < 0 ? $m(-$node->closing) : '' }}</td>
                </tr>
                @if ($detailed)
                    @foreach ($node->ledgers as $r)
                        @continue(! $r->opening && ! $r->debit && ! $r->credit)
                        <tr>
                            <td class="indent-{{ min($node->depth + 1, 3) }}"><a href="{{ route('reports.ledger', ['ledger' => $r->account->id, 'from' => $from, 'to' => $to]) }}">{{ $r->account->name }}</a></td>
                            <td class="amount">{{ $dc($r->opening) }}</td>
                            <td class="amount">{{ $m($r->debit) }}</td>
                            <td class="amount">{{ $m($r->credit) }}</td>
                            <td class="amount">{{ $r->closing > 0 ? $m($r->closing) : '' }}</td>
                            <td class="amount">{{ $r->closing < 0 ? $m(-$r->closing) : '' }}</td>
                        </tr>
                    @endforeach
                @endif
            @endforeach
            @if ($accumulated)
                <tr class="row-group">
                    <td>Profit &amp; Loss A/c <small class="text-secondary fw-normal">(previous years)</small></td>
                    <td class="amount">{{ $dc($accumulated) }}</td><td></td><td></td>
                    <td class="amount">{{ $accumulated > 0 ? $m($accumulated) : '' }}</td>
                    <td class="amount">{{ $accumulated < 0 ? $m(-$accumulated) : '' }}</td>
                </tr>
            @endif
            @if ($difference)
                <tr class="row-group text-danger">
                    <td>Difference in opening balances</td>
                    <td class="amount">{{ $dc(-$difference) }}</td><td></td><td></td>
                    <td class="amount">{{ $difference < 0 ? $m(-$difference) : '' }}</td>
                    <td class="amount">{{ $difference > 0 ? $m($difference) : '' }}</td>
                </tr>
            @endif
            </tbody>
            <tfoot>
                <tr class="row-total">
                    <td>Grand total</td><td></td>
                    <td class="amount">{{ $m($totals['debit']) ?: '0.00' }}</td>
                    <td class="amount">{{ $m($totals['credit']) ?: '0.00' }}</td>
                    <td class="amount">{{ $m($totals['closing_dr']) ?: '0.00' }}</td>
                    <td class="amount">{{ $m($totals['closing_cr']) ?: '0.00' }}</td>
                </tr>
            </tfoot>
        </table>
    </div>
</div>
@endsection
