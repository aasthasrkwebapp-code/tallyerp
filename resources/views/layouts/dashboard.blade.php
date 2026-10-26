
@extends('layouts.erp')

@section('title', 'Dashboard')
@section('page-heading', 'Dashboard Overview')

@section('content')
<div class="mb-4">
    <h4 class="fw-bold mb-1">Welcome, {{ auth()->user()->name }}!</h4>
    <p class="text-secondary mb-0">
        Your accounting and business management workspace.
    </p>
</div>

<div class="alert alert-info border-0">
    <i class="bi bi-info-circle me-2"></i>
    Phase 1 setup: dashboard layout is ready for module integration.
    Financial figures will appear after the accounting modules are implemented.
</div>

<div class="row g-3 mb-4">
    @foreach ([
        ['Users', 'bi-people', 'Registered account', 'Your login is active'],
        ['Accounting', 'bi-journal-check', 'Module setup', 'Coming in the next phase'],
        ['Sales', 'bi-receipt', 'Invoice management', 'Not implemented yet'],
        ['Reports', 'bi-graph-up', 'Financial reports', 'Not implemented yet'],
    ] as $item)
        <div class="col-12 col-sm-6 col-xl-3">
            <div class="erp-card">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <div class="text-secondary small">{{ $item[0] }}</div>
                        <div class="fw-semibold mt-2">{{ $item[2] }}</div>
                        <small class="text-secondary">{{ $item[3] }}</small>
                    </div>
                    <div class="erp-icon">
                        <i class="bi {{ $item[1] }}"></i>
                    </div>
                </div>
            </div>
        </div>
    @endforeach
</div>

<h5 class="fw-bold mb-3">ERP Modules</h5>

<div class="row g-3">
    @foreach ([
        ['accounting', 'Accounting & Ledgers', 'Chart of accounts, vouchers and ledgers', 'bi-journal-text'],
        ['sales', 'Sales Management', 'Invoices, receipts and customer balances', 'bi-receipt'],
        ['purchases', 'Purchase Management', 'Suppliers, bills and payments', 'bi-cart3'],
        ['inventory', 'Inventory', 'Items, stock and warehouses', 'bi-box-seam'],
        ['reports', 'Reports & Analytics', 'Financial statements and summaries', 'bi-bar-chart-line'],
        ['settings', 'Company Settings', 'Company profile and financial year', 'bi-gear'],
    ] as $module)
        <div class="col-12 col-md-6 col-xl-4" id="{{ $module[0] }}">
            <a href="#{{ $module[0] }}" class="erp-module">
                <div class="erp-card">
                    <div class="d-flex align-items-center gap-3">
                        <div class="erp-icon">
                            <i class="bi {{ $module[3] }}"></i>
                        </div>
                        <div>
                            <div class="fw-semibold">{{ $module[1] }}</div>
                            <small class="text-secondary">{{ $module[2] }}</small>
                        </div>
                    </div>
                    <div class="text-secondary small mt-3">
                        <i class="bi bi-tools me-1"></i>Module planned
                    </div>
                </div>
            </a>
        </div>
    @endforeach
</div>
@endsection
