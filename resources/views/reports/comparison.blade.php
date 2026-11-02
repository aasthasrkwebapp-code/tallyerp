@extends('layouts.erp')

@section('title', 'Period Comparison')

@php($m = fn ($p) => \App\Support\Money::format($p, paiseInput: true))
@php($signed = fn ($p) => $p < 0 ? '('.$m(-$p).')' : $m($p))
@php($P = \App\Services\Reports\PeriodComparison::class)

@section('content')
<div class="page-header">
    <div>
        <h1>{{ $statement === 'bs' ? 'Balance sheet' : 'Profit & loss' }} comparison</h1>
        <p class="text-secondary mb-0">{{ $P::MODES[$mode] }}{{ $statement === 'bs' ? ', as on the last day of each column' : '' }}</p>
    </div>
    <a href="{{ request()->fullUrlWithQuery(['detailed' => $detailed ? 0 : 1]) }}" class="btn btn-outline-secondary no-print">{{ $detailed ? 'Condensed' : 'Detailed' }}</a>
</div>

<form method="GET" class="erp-card p-3 mb-3 no-print">
    <input type="hidden" name="detailed" value="{{ $detailed ? 1 : 0 }}">
    <div class="row g-2 align-items-end">
        <div class="col-12 col-md-3">
            <label class="form-label small" for="c-statement">Statement</label>
            <select id="c-statement" name="statement" class="form-select form-select-sm">
                <option value="pl" @selected($statement === 'pl')>Profit &amp; loss</option>
                <option value="bs" @selected($statement === 'bs')>Balance sheet</option>
            </select>
        </div>
        <div class="col-12 col-md-3">
            <label class="form-label small" for="c-mode">Columns</label>
            <select id="c-mode" name="mode" class="form-select form-select-sm">
                @foreach ($P::MODES as $k => $label)<option value="{{ $k }}" @selected($mode === $k)>{{ $label }}</option>@endforeach
            </select>
        </div>
        <div class="col-6 col-md-2">
            <label class="form-label small" for="c-from">From</label>
            <input id="c-from" type="date" name="from" value="{{ $from }}" class="form-control form-control-sm">
        </div>
        <div class="col-6 col-md-2">
            <label class="form-label small" for="c-to">To</label>
            <input id="c-to" type="date" name="to" value="{{ $to }}" class="form-control form-control-sm">
        </div>
        <div class="col-12 col-md-auto d-flex flex-wrap gap-2">
            <button class="btn btn-sm btn-primary">Show</button>
            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="window.print()"><i class="bi bi-printer"></i> Print</button>
            @can('reports.export')
                <a class="btn btn-sm btn-outline-secondary" href="{{ request()->fullUrlWithQuery(['export' => 'csv', 'from' => $from, 'to' => $to]) }}"><i class="bi bi-filetype-csv"></i> CSV</a>
                <a class="btn btn-sm btn-outline-secondary" href="{{ request()->fullUrlWithQuery(['export' => 'xlsx', 'from' => $from, 'to' => $to]) }}"><i class="bi bi-file-earmark-excel"></i> Excel</a>
                <a class="btn btn-sm btn-outline-secondary" href="{{ request()->fullUrlWithQuery(['export' => 'pdf', 'from' => $from, 'to' => $to]) }}"><i class="bi bi-file-earmark-pdf"></i> PDF</a>
            @endcan
        </div>
    </div>
</form>
<div class="d-none d-print-block mb-2">
    <div class="fw-bold">{{ $currentCompany->displayName() }}</div>
    <div class="small">{{ $statement === 'bs' ? 'Balance sheet' : 'Profit & loss' }} comparison, {{ \Illuminate\Support\Carbon::parse($from)->format('d M Y') }} to {{ \Illuminate\Support\Carbon::parse($to)->format('d M Y') }}</div>
</div>

@if ($truncated)
    <div class="alert alert-warning">Only the first {{ $P::MAX_COLUMNS }} columns are shown. Choose a shorter period or larger columns to see the rest.</div>
@endif

<div class="erp-card">
    <div class="table-responsive">
        <table class="table table-sm table-report mb-0 text-nowrap table-sticky-first">
            <thead class="table-light">
                <tr>
                    <th>Particulars</th>
                    @foreach ($columns as $c)
                        <th class="text-end">{{ $c['label'] }}@if ($statement === 'bs' && $mode !== 'previous')<div class="small fw-normal text-secondary">as on {{ \Illuminate\Support\Carbon::parse($c['to'])->format('d-m-Y') }}</div>@endif</th>
                    @endforeach
                    @if ($showChange)<th class="text-end">Change</th><th class="text-end">%</th>@endif
                    @if ($showTotal)<th class="text-end">Total</th>@endif
                </tr>
            </thead>
            <tbody>
            @foreach ($rows as $r)
                @if ($r->kind === 'heading')
                    <tr class="row-group"><td>{{ $r->label }}</td><td colspan="{{ count($columns) + ($showChange ? 2 : 0) + ($showTotal ? 1 : 0) }}"></td></tr>
                    @continue
                @endif
                <tr @class(['row-total' => in_array($r->kind, ['total', 'grand'], true), 'text-secondary' => in_array($r->kind, ['child', 'empty'], true)])>
                    <td class="indent-{{ max($r->depth, 0) }}">
                        @if ($r->link && $r->link[0] === 'group')
                            <a href="{{ route('reports.group-summary', ['group' => $r->link[1], 'from' => $from, 'to' => $to]) }}" class="text-body @if ($r->kind === 'child') fst-italic @endif">{{ $r->label }}</a>
                        @elseif ($r->link && $r->link[0] === 'ledger')
                            <a href="{{ route('reports.ledger', ['ledger' => $r->link[1], 'from' => $from, 'to' => $to]) }}">{{ $r->label }}</a>
                        @else
                            {{ $r->label }}
                        @endif
                    </td>
                    @foreach ($r->amounts as $a)
                        <td class="amount">{{ $a === null ? '' : $signed($a) }}</td>
                    @endforeach
                    @if ($showChange)
                        @php([$diff, $pct] = $r->kind === 'empty' ? [null, null] : $P::change($r->amounts))
                        <td class="amount">{{ $diff === null ? '' : $signed($diff) }}</td>
                        <td class="text-end small">{{ $pct === null ? ($diff ? 'new' : '') : $pct.'%' }}</td>
                    @endif
                    @if ($showTotal)
                        <td class="amount fw-semibold">{{ $r->total === null ? '' : $signed($r->total) }}</td>
                    @endif
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
</div>
<p class="small text-secondary mt-2">Each column is the ordinary {{ $statement === 'bs' ? 'balance sheet' : 'profit & loss account' }} for that period, so it agrees with the single-period report. Losses and negative balances are shown in brackets.</p>
@endsection
