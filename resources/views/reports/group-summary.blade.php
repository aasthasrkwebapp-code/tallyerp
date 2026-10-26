@extends('layouts.erp')

@section('title', 'Group Summary')

@php($m = fn ($p) => $p ? \App\Support\Money::format($p, paiseInput: true) : '')

@section('content')
<div class="page-header">
    <div>
        <h1>Group summary: {{ $group->name }}</h1>
        <p class="text-secondary mb-0">{{ $group->path() }}</p>
    </div>
    @if ($group->parent_id)
        <a href="{{ route('reports.group-summary', ['group' => $group->parent_id, 'from' => $from, 'to' => $to]) }}" class="btn btn-outline-secondary no-print">Up one level</a>
    @endif
</div>

@include('reports._period')

<div class="erp-card">
    <div class="table-responsive">
        <table class="table table-sm table-report mb-0 table-stack">
            <thead class="table-light"><tr><th>Particulars</th><th class="text-end">Opening</th><th class="text-end">Debit</th><th class="text-end">Credit</th><th class="text-end">Closing</th></tr></thead>
            <tbody>
            @foreach ($children as $child)
                <tr class="row-group">
                    <td data-label="Group"><a href="{{ route('reports.group-summary', ['group' => $child->group->id, 'from' => $from, 'to' => $to]) }}" class="text-body">{{ $child->group->name }}</a></td>
                    <td data-label="Opening" class="amount">{{ \App\Support\Money::drCr($child->opening) }}</td>
                    <td data-label="Debit" class="amount">{{ $m($child->debit) }}</td>
                    <td data-label="Credit" class="amount">{{ $m($child->credit) }}</td>
                    <td data-label="Closing" class="amount">{{ \App\Support\Money::drCr($child->closing) }}</td>
                </tr>
            @endforeach
            @foreach ($node->ledgers as $r)
                <tr>
                    <td data-label="Ledger"><a href="{{ route('reports.ledger', ['ledger' => $r->account->id, 'from' => $from, 'to' => $to]) }}">{{ $r->account->name }}</a></td>
                    <td data-label="Opening" class="amount">{{ \App\Support\Money::drCr($r->opening) }}</td>
                    <td data-label="Debit" class="amount">{{ $m($r->debit) }}</td>
                    <td data-label="Credit" class="amount">{{ $m($r->credit) }}</td>
                    <td data-label="Closing" class="amount">{{ \App\Support\Money::drCr($r->closing) }}</td>
                </tr>
            @endforeach
            </tbody>
            <tfoot>
                <tr class="row-total">
                    <td data-label="">Total</td>
                    <td class="amount" data-label="Opening">{{ \App\Support\Money::drCr($node->opening) }}</td>
                    <td class="amount" data-label="Debit">{{ $m($node->debit) }}</td>
                    <td class="amount" data-label="Credit">{{ $m($node->credit) }}</td>
                    <td class="amount" data-label="Closing">{{ \App\Support\Money::drCr($node->closing) }}</td>
                </tr>
            </tfoot>
        </table>
    </div>
</div>
@endsection
