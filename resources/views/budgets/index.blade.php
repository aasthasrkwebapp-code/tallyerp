@extends('layouts.erp')

@section('title', 'Budget vs Actual')

@section('content')
@php
    $sections = $rows->groupBy(fn ($r) => $r->income ? 'Income' : 'Expenses');
@endphp
<div class="container-fluid py-3">
    @include('reports._header', [
        'title' => 'Budget vs Actual',
        'subtitle' => 'FY '.$company->financial_year.' · up to '.$to->format('d-m-Y').' ('.round($elapsed * 100).'% of the year)',
    ])

    @if (session('success'))
        <div class="alert alert-success d-print-none">{{ session('success') }}</div>
    @endif

    <div class="d-flex flex-wrap gap-2 mb-3 d-print-none">
        <a href="{{ route('companies.budgets.edit', $company) }}" class="btn btn-primary">Set budgets</a>
    </div>

    @include('reports._as-on')

    @forelse ($sections as $name => $items)
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white fw-semibold">{{ $name }}</div>
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Ledger</th>
                            <th class="text-end">Year budget</th>
                            <th class="text-end">Budget to date</th>
                            <th class="text-end">Actual</th>
                            <th class="text-end">Variance</th>
                            <th style="width: 180px">Used</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($items as $row)
                            <tr>
                                <td>{{ $row->account->name }}</td>
                                <td class="text-end">{{ $row->budget ? inr($row->budget) : '—' }}</td>
                                <td class="text-end">{{ $row->budget ? inr($row->budget_to_date) : '' }}</td>
                                <td class="text-end">{{ inr($row->actual) }}</td>
                                <td class="text-end fw-semibold {{ $row->budget ? ($row->variance >= 0 ? 'text-success' : 'text-danger') : 'text-secondary' }}">
                                    {{ $row->budget ? ($row->variance >= 0 ? '+' : '−').inr(abs($row->variance)) : 'No budget' }}
                                </td>
                                <td>
                                    @if ($row->used !== null)
                                        @php $over = ! $row->income && $row->used > 100; @endphp
                                        <div class="progress" style="height: 8px" role="progressbar" aria-label="{{ $row->used }}% used"
                                            aria-valuenow="{{ $row->used }}" aria-valuemin="0" aria-valuemax="100">
                                            <div class="progress-bar {{ $over ? 'bg-danger' : '' }}" style="width: {{ min(100, $row->used) }}%"></div>
                                        </div>
                                        <small class="{{ $over ? 'text-danger' : 'text-secondary' }}">{{ $row->used }}%</small>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot class="fw-semibold">
                        <tr>
                            <td>Total {{ strtolower($name) }}</td>
                            <td class="text-end">{{ inr($items->sum('budget')) }}</td>
                            <td class="text-end">{{ inr($items->sum('budget_to_date')) }}</td>
                            <td class="text-end">{{ inr($items->sum('actual')) }}</td>
                            <td colspan="2"></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    @empty
        <div class="card border-0 shadow-sm">
            <div class="card-body text-center text-secondary py-4">
                No budgets or income/expense entries yet. <a href="{{ route('companies.budgets.edit', $company) }}">Set budgets</a>
            </div>
        </div>
    @endforelse

    <p class="small text-secondary">Budget to date is the year budget spread evenly over the year. Green variance is favourable (more income, or less spent, than planned).</p>
</div>
@endsection
