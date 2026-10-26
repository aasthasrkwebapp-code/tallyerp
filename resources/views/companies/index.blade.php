
@extends('layouts.erp')

@section('content')
<div class="container-fluid py-3">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
        <div>
            <h2 class="h4 mb-1">Companies</h2>
            <p class="text-secondary mb-0">Manage your business profiles.</p>
        </div>
        <a href="{{ route('companies.create') }}" class="btn btn-primary">
            + Create Company
        </a>
    </div>

    @if (session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif

    <div class="card border-0 shadow-sm">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Company</th>
                        <th>GSTIN</th>
                        <th>Financial Year</th>
                        <th>Currency</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($companies as $company)
                        <tr>
                            <td>
                                <div class="fw-semibold">{{ $company->name }}</div>
                                <small class="text-secondary">{{ $company->email }}</small>
                            </td>
                            <td>{{ $company->gstin ?: '—' }}</td>
                            <td>{{ $company->financial_year }}</td>
                            <td>{{ $company->currency }}</td>
                            <td class="text-end">
                                <div class="btn-group btn-group-sm" role="group">
                                    <a href="{{ route('companies.show', $company) }}"
                                        class="btn btn-outline-secondary">
                                        <i class="bi bi-eye"></i>
                                    </a>
                                    <a href="{{ route('companies.edit', $company) }}"
                                        class="btn btn-outline-primary">
                                        <i class="bi bi-pencil"></i>
                                    </a>
                                    <form method="POST"
                                        action="{{ route('companies.destroy', $company) }}"
                                        onsubmit="return confirm('Are you sure you want to delete {{ $company->name }}?');">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="btn btn-outline-danger">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="text-center py-5 text-secondary">
                                No companies created yet.
                                <div class="mt-2">
                                    <a href="{{ route('companies.create') }}">Create your first company</a>
                                </div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
