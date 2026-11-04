@extends('layouts.erp')

@section('title', 'Dashboard')

@section('content')
<div class="container-fluid py-3">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
        <div>
            <h2 class="h4 mb-1">Dashboard</h2>
            <p class="text-secondary mb-0">Welcome, {{ auth()->user()->name }}</p>
        </div>
        <a href="{{ route('companies.create') }}" class="btn btn-primary">+ Create Company</a>
    </div>

    @if ($companies->isEmpty())
        <div class="card border-0 shadow-sm">
            <div class="card-body text-center py-5">
                <div class="h5">No companies yet</div>
                <p class="text-secondary">Create your first company to start keeping books.</p>
                <a href="{{ route('companies.create') }}" class="btn btn-primary">Create Company</a>
            </div>
        </div>
    @else
        <div class="row g-3">
            @foreach ($companies as [$company, $role, $f])
                <div class="col-12 col-lg-6">
                    <a href="{{ route('companies.show', $company) }}" class="card border-0 shadow-sm h-100 text-decoration-none">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-start mb-2">
                                <div>
                                    <div class="fw-semibold text-body">{{ $company->name }}</div>
                                    <div class="small text-secondary">FY {{ $company->financial_year }} · {{ ucfirst($role) }}</div>
                                </div>
                                <i class="bi bi-chevron-right text-secondary"></i>
                            </div>
                            @if ($f)
                                <div class="row g-2 small">
                                    <div class="col-4"><div class="text-secondary">Cash &amp; Bank</div><div class="fw-semibold text-body">₹ {{ inr($f->cash_bank) }}</div></div>
                                    <div class="col-4"><div class="text-secondary">Receivables</div><div class="fw-semibold text-body">₹ {{ inr($f->receivables) }}</div></div>
                                    <div class="col-4">
                                        <div class="text-secondary">{{ $f->net_profit >= 0 ? 'Net profit' : 'Net loss' }}</div>
                                        <div class="fw-semibold {{ $f->net_profit >= 0 ? 'text-success' : 'text-danger' }}">₹ {{ inr(abs($f->net_profit)) }}</div>
                                    </div>
                                </div>
                            @endif
                        </div>
                    </a>
                </div>
            @endforeach
        </div>
    @endif
</div>
@endsection
