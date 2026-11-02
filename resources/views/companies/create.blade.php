@extends('layouts.erp')

@section('title', 'Create Company')

@section('content')
<div class="page-header">
    <div>
        <h1>Create company</h1>
        <p class="text-secondary mb-0">Default account groups, ledgers and voucher types are created automatically.</p>
    </div>
    <a href="{{ route('companies.index') }}" class="btn btn-outline-secondary">Back</a>
</div>

<form method="POST" action="{{ route('companies.store') }}" class="erp-card p-3 p-md-4">
    @csrf
    @include('companies._form')
    <div class="d-flex flex-wrap gap-2 mt-4">
        <button type="submit" class="btn btn-primary" data-shortcut="s">Create company</button>
        <a href="{{ route('companies.index') }}" class="btn btn-light border">Cancel</a>
    </div>
</form>
@endsection
