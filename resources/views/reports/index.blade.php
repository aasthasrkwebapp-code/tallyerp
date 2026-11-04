@extends('layouts.erp')

@section('title', 'Reports')

@section('content')
<div class="container-fluid py-3">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
        <div>
            <h2 class="h4 mb-1">Reports</h2>
            <p class="text-secondary mb-0">{{ $company->name }} · FY {{ $company->financial_year }}</p>
        </div>
        <a href="{{ route('companies.show', $company) }}" class="btn btn-outline-secondary">Back to Company</a>
    </div>

    <div class="row g-3">
        @foreach ([
            ['companies.reports.balance-sheet', 'Balance Sheet', 'Assets and liabilities on a date.'],
            ['companies.reports.profit-and-loss', 'Profit & Loss', 'Gross and net profit for the year.'],
            ['companies.reports.trial-balance', 'Trial Balance', 'Closing balance of every ledger.'],
            ['companies.reports.ledger', 'Ledger', 'All transactions of one ledger with running balance.'],
            ['companies.vouchers.index', 'Day Book', 'All vouchers by date.'],
        ] as [$route, $name, $description])
            <div class="col-12 col-md-6 col-xl-4">
                <a href="{{ route($route, $company) }}" class="card border-0 shadow-sm h-100 text-decoration-none">
                    <div class="card-body">
                        <div class="fw-semibold text-body">{{ $name }}</div>
                        <div class="small text-secondary">{{ $description }}</div>
                    </div>
                </a>
            </div>
        @endforeach
    </div>
</div>
@endsection
