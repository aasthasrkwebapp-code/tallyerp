@extends('layouts.erp')

@section('content')
<div class="container-fluid py-3">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
        <div>
            <h2 class="h4 mb-1">{{ $company->name }}</h2>
            <p class="text-secondary mb-0">Company profile</p>
        </div>
        <div class="d-flex gap-2">
            <a href="{{ route('companies.edit', $company) }}" class="btn btn-outline-primary">
                Edit
            </a>
            <a href="{{ route('companies.index') }}" class="btn btn-outline-secondary">
                Back to Companies
            </a>
        </div>
    </div>

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
                    <dd class="mb-0">{{ $company->financial_year }}</dd>
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
