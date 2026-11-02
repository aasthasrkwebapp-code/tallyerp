@extends('layouts.erp')

@section('title', 'Companies')

@section('content')
<div class="page-header">
    <div>
        <h1>Companies</h1>
        <p class="text-secondary mb-0">Companies you can work in. Select one to make it active.</p>
    </div>
    <a href="{{ route('companies.create') }}" class="btn btn-primary"><i class="bi bi-plus-lg me-1"></i>Create company</a>
</div>

<div class="erp-card">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0 table-stack">
            <thead class="table-light">
            <tr>
                <th>Company</th>
                <th>GSTIN</th>
                <th>State</th>
                <th>Your role</th>
                <th class="text-end">Actions</th>
            </tr>
            </thead>
            <tbody>
            @forelse ($companies as $company)
                <tr>
                    <td data-label="Company">
                        <div class="fw-semibold">{{ $company->name }}
                            @if ($company->id === $activeId)<span class="badge text-bg-success ms-1">Active</span>@endif
                        </div>
                        <small class="text-secondary">{{ $company->email }}</small>
                    </td>
                    <td data-label="GSTIN">{{ $company->gstin ?: '—' }}</td>
                    <td data-label="State">{{ $company->state ?: '—' }}</td>
                    <td data-label="Role" class="text-capitalize">{{ $roles[$company->pivot->role_id] ?? '—' }}</td>
                    <td data-label="" class="text-end">
                        <div class="d-inline-flex gap-1">
                            @if ($company->id !== $activeId)
                                <form method="POST" action="{{ route('companies.switch', $company) }}">
                                    @csrf
                                    <button class="btn btn-sm btn-primary">Open</button>
                                </form>
                            @endif
                            <a href="{{ route('companies.show', $company) }}" class="btn btn-sm btn-outline-secondary" aria-label="View {{ $company->name }}"><i class="bi bi-eye"></i></a>
                        </div>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="5" class="text-center py-5 text-secondary">
                        No companies yet. <a href="{{ route('companies.create') }}">Create your first company</a>.
                    </td>
                </tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
