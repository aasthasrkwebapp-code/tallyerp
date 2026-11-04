@extends('layouts.erp')

@section('content')
<div class="container-fluid py-3">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
        <div>
            <h2 class="h4 mb-1">{{ $company->name }}</h2>
            <p class="text-secondary mb-0">Company profile</p>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <a href="{{ route('companies.vouchers.create', $company) }}" class="btn btn-primary">
                New Voucher
            </a>
            <a href="{{ route('companies.gst-invoices.create', [$company, 'type' => 'sales']) }}" class="btn btn-outline-primary">
                Sales Invoice
            </a>
            <a href="{{ route('companies.gst-invoices.create', [$company, 'type' => 'purchase']) }}" class="btn btn-outline-primary">
                Purchase Invoice
            </a>
            <a href="{{ route('companies.vouchers.index', $company) }}" class="btn btn-outline-primary">
                Day Book
            </a>
            <a href="{{ route('companies.reports.index', $company) }}" class="btn btn-outline-primary">
                Reports
            </a>
            <a href="{{ route('companies.stock-items.index', $company) }}" class="btn btn-outline-primary">
                Stock Items
            </a>
            <a href="{{ route('companies.accounts.index', $company) }}" class="btn btn-outline-primary">
                Ledgers
            </a>
            <a href="{{ route('companies.account-groups.index', $company) }}" class="btn btn-outline-primary">
                Groups
            </a>
            <a href="{{ route('companies.edit', $company) }}" class="btn btn-outline-primary">
                Edit
            </a>
            <a href="{{ route('companies.index') }}" class="btn btn-outline-secondary">
                Back to Companies
            </a>
        </div>
    </div>

    @if (session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif
    @error('year')
        <div class="alert alert-danger">{{ $message }}</div>
    @enderror

    @if ($figures)
        @include('companies._dashboard', ['f' => $figures])
    @endif

    <div class="card border-0 shadow-sm">
        <div class="card-body p-3 p-md-4">
            <div class="row g-3">
                <div class="col-12 col-md-6">
                    <dt class="text-secondary small mb-1">Legal Name</dt>
                    <dd class="mb-0">{{ $company->legal_name ?: '—' }}</dd>
                </div>

                <div class="col-12 col-md-6">
                    <dt class="text-secondary small mb-1">Company Name</dt>
                    <dd class="mb-0">{{ $company->name }}</dd>
                </div>

                <div class="col-12 col-md-6">
                    <dt class="text-secondary small mb-1">GSTIN</dt>
                    <dd class="mb-0">{{ $company->gstin ?: '—' }}</dd>
                </div>

                <div class="col-12 col-md-6">
                    <dt class="text-secondary small mb-1">PAN</dt>
                    <dd class="mb-0">{{ $company->pan ?: '—' }}</dd>
                </div>

                <div class="col-12 col-md-6">
                    <dt class="text-secondary small mb-1">Email</dt>
                    <dd class="mb-0">{{ $company->email ?: '—' }}</dd>
                </div>

                <div class="col-12 col-md-6">
                    <dt class="text-secondary small mb-1">Phone</dt>
                    <dd class="mb-0">{{ $company->phone ?: '—' }}</dd>
                </div>

                <div class="col-12">
                    <dt class="text-secondary small mb-1">Business Address</dt>
                    <dd class="mb-0">{{ $company->address ?: '—' }}</dd>
                </div>

                <div class="col-12 col-md-6">
                    <dt class="text-secondary small mb-1">State</dt>
                    <dd class="mb-0">{{ $company->state ?: '—' }}</dd>
                </div>

                <div class="col-12 col-md-6">
                    <dt class="text-secondary small mb-1">Financial Year</dt>
                    <dd class="mb-0">
                        @php $currentStart = (int) substr($company->financial_year, 0, 4); @endphp
                        <form method="POST" action="{{ route('companies.financial-year', $company) }}" class="d-flex flex-wrap gap-2 align-items-center">
                            @csrf
                            @method('PUT')
                            <select name="year" class="form-select form-select-sm w-auto" aria-label="Financial year">
                                @foreach (range($company->books_start_year, max($currentStart, now()->year) + 1) as $y)
                                    <option value="{{ $y }}" @selected($y === $currentStart)>{{ sprintf('%d-%02d', $y, ($y + 1) % 100) }}</option>
                                @endforeach
                            </select>
                            <button class="btn btn-sm btn-outline-primary">Change year</button>
                        </form>
                        <div class="form-text">Books start {{ $company->books_start_year }}-{{ sprintf('%02d', ($company->books_start_year + 1) % 100) }}. Balances carry forward; income and expenses restart each year.</div>
                    </dd>
                </div>

                <div class="col-12 col-md-6">
                    <dt class="text-secondary small mb-1">Currency</dt>
                    <dd class="mb-0">{{ $company->currency }}</dd>
                </div>
            </div>
        </div>
    </div>

    <form id="delete-form" method="POST" action="{{ route('companies.destroy', $company) }}">
        @csrf
        @method('DELETE')
    </form>
    <button type="button" class="btn btn-outline-danger mt-3"
        onclick="if(confirm('Are you sure you want to delete this company? This cannot be undone.')) document.getElementById('delete-form').submit();">
        <i class="bi bi-trash me-1"></i>Delete Company
    </button>
</div>
@endsection
