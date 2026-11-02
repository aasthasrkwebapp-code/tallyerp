@extends('layouts.erp')

@section('title', 'Edit Company')

@section('content')
<div class="page-header">
    <div>
        <h1>Edit company</h1>
        <p class="text-secondary mb-0">{{ $company->name }}</p>
    </div>
    <a href="{{ route('companies.show', $company) }}" class="btn btn-outline-secondary">Back</a>
</div>

<form method="POST" action="{{ route('companies.update', $company) }}" class="erp-card p-3 p-md-4">
    @csrf
    @method('PUT')
    @include('companies._form')
    <div class="d-flex flex-wrap gap-2 mt-4">
        <button type="submit" class="btn btn-primary" data-shortcut="s">Save changes</button>
        <a href="{{ route('companies.show', $company) }}" class="btn btn-light border">Cancel</a>
    </div>
</form>
@endsection
