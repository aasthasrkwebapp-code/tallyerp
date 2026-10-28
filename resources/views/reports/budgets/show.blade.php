@extends('layouts.erp')

@section('title', $budget->name)

@php($m = fn ($p) => \App\Support\Money::format($p, paiseInput: true))
@php($signed = fn ($p) => $p < 0 ? '('.$m(-$p).')' : $m($p))

@section('content')
<div class="page-header">
    <div>
        <h1>{{ $budget->name }}</h1>
        <p class="text-secondary mb-0">Budget {{ $budget->from_date->format('d M Y') }} to {{ $budget->to_date->format('d M Y') }} · actuals up to {{ \Illuminate\Support\Carbon::parse($as_of)->format('d M Y') }} (day {{ $elapsed }} of {{ $days }})</p>
        @if ($budget->description)<p class="small mb-0">{{ $budget->description }}</p>@endif
    </div>
    <div class="d-flex flex-wrap gap-2 no-print">
        <a href="{{ route('budgets.index') }}" class="btn btn-outline-secondary">All budgets</a>
        @can('masters.edit')<a href="{{ route('budgets.edit', $budget) }}" class="btn btn-outline-primary" data-shortcut="e"><i class="bi bi-pencil me-1"></i>Alter</a>@endcan
        @can('masters.create')
            <form method="POST" action="{{ route('budgets.copy', $budget) }}">@csrf<button class="btn btn-outline-secondary"><i class="bi bi-copy me-1"></i>Copy to next year</button></form>
        @endcan
        @can('masters.delete')
            <form method="POST" action="{{ route('budgets.destroy', $budget) }}" data-confirm="Delete this budget? The books are not changed.">@csrf @method('DELETE')<button class="btn btn-outline-danger"><i class="bi bi-trash"></i><span class="visually-hidden">Delete</span></button></form>
        @endcan
    </div>
</div>

<form method="GET" class="erp-card p-3 mb-3 no-print">
    <div class="row g-2 align-items-end">
        <div class="col-6 col-md-3">
            <label class="form-label small" for="as_of">Actuals up to</label>
            <input id="as_of" type="date" name="as_of" value="{{ $as_of }}" min="{{ $budget->from_date->toDateString() }}" max="{{ $budget->to_date->toDateString() }}" class="form-control form-control-sm">
        </div>
        <div class="col-12 col-md-auto d-flex flex-wrap gap-2">
            <button class="btn btn-sm btn-primary">Show</button>
            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="window.print()"><i class="bi bi-printer"></i> Print</button>
            @can('reports.export')
                <a class="btn btn-sm btn-outline-secondary" href="{{ request()->fullUrlWithQuery(['export' => 'csv', 'as_of' => $as_of]) }}"><i class="bi bi-filetype-csv"></i> CSV</a>
                <a class="btn btn-sm btn-outline-secondary" href="{{ request()->fullUrlWithQuery(['export' => 'xlsx', 'as_of' => $as_of]) }}"><i class="bi bi-file-earmark-excel"></i> Excel</a>
                <a class="btn btn-sm btn-outline-secondary" href="{{ request()->fullUrlWithQuery(['export' => 'pdf', 'as_of' => $as_of]) }}"><i class="bi bi-file-earmark-pdf"></i> PDF</a>
            @endcan
        </div>
    </div>
</form>
<div class="d-none d-print-block mb-2"><div class="fw-bold">{{ $currentCompany->displayName() }}</div></div>

@php($over = $rows->where('flag', 'over')->count())
@php($short = $rows->where('flag', 'short')->count())
@if ($over || $short)
    <div class="alert alert-warning py-2">
        @if ($over){{ $over }} {{ \Illuminate\Support\Str::plural('expense line', $over) }} over budget.@endif
        @if ($short){{ $short }} {{ \Illuminate\Support\Str::plural('income line', $short) }} short of target.@endif
    </div>
@endif

<div class="erp-card">
    <div class="table-responsive">
        <table class="table align-middle mb-0 table-stack">
            <thead class="table-light"><tr><th>Group / ledger</th><th>Basis</th><th class="text-end">Budget</th><th class="text-end">Budget to date</th><th class="text-end">Actual</th><th class="text-end">Variance</th><th style="min-width: 9rem">Used</th></tr></thead>
            <tbody>
            @foreach ($rows as $r)
                <tr>
                    <td data-label="Group / ledger">
                        @if ($r->kind === 'ledger')
                            <a href="{{ route('reports.ledger', ['ledger' => $r->line->account_id, 'from' => $budget->from_date->toDateString(), 'to' => $as_of]) }}" class="fw-semibold">{{ $r->name }}</a>
                        @else
                            <a href="{{ route('reports.group-summary', ['group' => $r->line->account_group_id, 'from' => $budget->from_date->toDateString(), 'to' => $as_of]) }}" class="fw-semibold text-body">{{ $r->name }}</a> <span class="small text-secondary">(group)</span>
                        @endif
                        @if ($r->line->costCentre)<div class="small text-secondary"><i class="bi bi-bullseye"></i> {{ $r->line->costCentre->name }}</div>@endif
                    </td>
                    <td data-label="Basis" class="small">{{ \App\Models\BudgetLine::BASES[$r->line->basis] }}</td>
                    <td data-label="Budget" class="amount">{{ $m($r->budget) }}</td>
                    <td data-label="Budget to date" class="amount">{{ $m($r->budget_to_date) }}</td>
                    <td data-label="Actual" class="amount">{{ $signed($r->actual) }}</td>
                    <td data-label="Variance" class="amount @if ($r->flag) text-danger fw-semibold @endif">{{ $signed($r->variance) }}
                        @if ($r->flag === 'over')<div class="small">Over budget</div>@elseif ($r->flag === 'short')<div class="small">Short of target</div>@endif
                    </td>
                    <td data-label="Used">
                        @if ($r->percent === null)
                            <span class="text-secondary small">n/a</span>
                        @else
                            <div class="d-flex align-items-center gap-2">
                                <div class="progress flex-grow-1" role="progressbar" aria-label="Share of budget used" aria-valuenow="{{ $r->percent }}" aria-valuemin="0" aria-valuemax="100" style="height: .5rem">
                                    <div class="progress-bar {{ $r->flag ? 'bg-danger' : 'bg-success' }}" style="width: {{ max(0, min(100, $r->percent)) }}%"></div>
                                </div>
                                <span class="small text-nowrap">{{ $r->percent }}%</span>
                            </div>
                        @endif
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
</div>
<p class="small text-secondary mt-2">Variance is actual minus budget to date. Expenses above budget and income below target are marked. Group lines include their sub-groups, and cost centre lines include their sub-cost-centres. Only posted vouchers count.</p>
@endsection
