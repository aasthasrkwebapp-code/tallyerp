@extends('layouts.erp')

@section('title', $company->name)

@section('content')
<div class="page-header">
    <div>
        <h1>{{ $company->name }}</h1>
        <p class="text-secondary mb-0">Company profile</p>
    </div>
    <div class="d-flex flex-wrap gap-2">
        @if (auth()->user()->hasPermission('company.edit', $company))
            <a href="{{ route('companies.edit', $company) }}" class="btn btn-outline-primary"><i class="bi bi-pencil me-1"></i>Edit</a>
        @endif
        <a href="{{ route('companies.index') }}" class="btn btn-outline-secondary">All companies</a>
    </div>
</div>

<div class="erp-card p-3 p-md-4 mb-3">
    <dl class="row mb-0">
        @foreach ([
            'Legal name' => $company->legal_name,
            'Mailing name' => $company->mailing_name,
            'GSTIN' => $company->gstin,
            'PAN' => $company->pan,
            'TAN' => $company->tan,
            'CIN' => $company->cin,
            'Email' => $company->email,
            'Phone' => $company->phone,
            'Website' => $company->website,
            'Address' => trim(implode(', ', array_filter([$company->address, $company->city, $company->state, $company->pincode, $company->country]))),
            'Books beginning' => $company->books_begin_date?->format('d M Y'),
            'Currency' => $company->currency,
        ] as $label => $value)
            <div class="col-12 col-md-6 mb-3">
                <dt class="text-secondary small fw-normal">{{ $label }}</dt>
                <dd class="mb-0">{{ $value ?: '—' }}</dd>
            </div>
        @endforeach
    </dl>
</div>

@if (auth()->user()->hasPermission('company.delete', $company))
    <div class="erp-card p-3 p-md-4 border-danger-subtle">
        <h2 class="h6 fw-bold text-danger">Delete company</h2>
        <p class="small text-secondary">This permanently deletes the company and every record in it. Type the company name to confirm.</p>
        <form method="POST" action="{{ route('companies.destroy', $company) }}" class="row g-2" data-confirm="Delete {{ $company->name }} and all of its records?">
            @csrf
            @method('DELETE')
            <div class="col-12 col-sm-6 col-lg-4">
                <label for="confirm_name" class="visually-hidden">Company name</label>
                <input id="confirm_name" name="confirm_name" class="form-control" placeholder="{{ $company->name }}" autocomplete="off">
            </div>
            <div class="col-auto"><button class="btn btn-danger">Delete company</button></div>
        </form>
    </div>
@endif
@endsection
