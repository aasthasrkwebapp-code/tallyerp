@extends('layouts.erp')

@section('title', 'Ratio Analysis')

@php($m = fn ($p) => \App\Support\Money::format($p, paiseInput: true))
@php($R = \App\Services\Reports\RatioAnalysis::class)
@php($current = end($columns))

@section('content')
<div class="page-header">
    <div>
        <h1>Ratio analysis</h1>
        <p class="text-secondary mb-0">Balances as on {{ \Illuminate\Support\Carbon::parse($to)->format('d M Y') }}; sales and profit for {{ \Illuminate\Support\Carbon::parse($from)->format('d M Y') }} to {{ \Illuminate\Support\Carbon::parse($to)->format('d M Y') }} ({{ $current['days'] }} days)</p>
    </div>
</div>

<form method="GET" class="erp-card p-3 mb-3 no-print">
    <div class="row g-2 align-items-end">
        <div class="col-6 col-md-3">
            <label class="form-label small" for="r-from">From</label>
            <input id="r-from" type="date" name="from" value="{{ $from }}" class="form-control form-control-sm">
        </div>
        <div class="col-6 col-md-3">
            <label class="form-label small" for="r-to">To</label>
            <input id="r-to" type="date" name="to" value="{{ $to }}" class="form-control form-control-sm">
        </div>
        <div class="col-12 col-md-auto">
            <div class="form-check mb-1">
                <input class="form-check-input" type="checkbox" name="compare" value="1" id="r-compare" @checked($compare)>
                <label class="form-check-label small" for="r-compare">Compare with the same period last year</label>
            </div>
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
    <div class="small">Ratio analysis, {{ \Illuminate\Support\Carbon::parse($from)->format('d M Y') }} to {{ \Illuminate\Support\Carbon::parse($to)->format('d M Y') }}</div>
</div>

@php($heads = collect($columns)->map(fn ($c) => count($columns) > 1 ? \Illuminate\Support\Carbon::parse($c['to'])->format('d M Y') : 'Amount'))
<div class="row g-3">
    <div class="col-12 col-xl-6">
        <div class="erp-card h-100">
            <div class="p-3 pb-0"><h2 class="h6 mb-2">Principal groups</h2></div>
            <div class="table-responsive">
                <table class="table table-sm table-report mb-0">
                    <thead class="table-light"><tr><th>Particulars</th>@foreach ($heads as $h)<th class="text-end">{{ $h }}</th>@endforeach</tr></thead>
                    <tbody>
                    @foreach ($R::GROUPS as $key => $label)
                        <tr @class(['row-group' => in_array($key, ['working_capital', 'net_profit'], true)])>
                            <td>{{ $label }}</td>
                            @foreach ($columns as $c)
                                <td class="amount @if ($c['groups'][$key] < 0) text-danger @endif">{{ $c['groups'][$key] < 0 ? '('.$m(-$c['groups'][$key]).')' : $m($c['groups'][$key]) }}</td>
                            @endforeach
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <div class="col-12 col-xl-6">
        <div class="erp-card h-100">
            <div class="p-3 pb-0"><h2 class="h6 mb-2">Principal ratios</h2></div>
            <div class="table-responsive">
                <table class="table table-sm table-report mb-0">
                    <thead class="table-light"><tr><th>Ratio</th>@foreach ($heads as $h)<th class="text-end">{{ $h === 'Amount' ? 'Value' : $h }}</th>@endforeach</tr></thead>
                    <tbody>
                    @foreach ($R::RATIOS as $key => [$label, $unit, $basis])
                        <tr>
                            <td>{{ $label }}<div class="small text-secondary">{{ $basis }}</div></td>
                            @foreach ($columns as $c)
                                <td class="text-end text-nowrap fw-semibold">{{ $R::format($c['ratios'][$key], $unit) }}</td>
                            @endforeach
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
<p class="small text-secondary mt-2">Groups follow the default chart of accounts: sub-groups you create count under their parent. "n/a" means the divisor is zero or negative. Stock is valued at weighted average cost.</p>
@endsection
