@extends('layouts.erp')

@section('title', 'Groups')

@section('content')
<div class="container-fluid py-3">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
        <div>
            <h2 class="h4 mb-1">Account Groups</h2>
            <p class="text-secondary mb-0">{{ $company->name }}</p>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <a href="{{ route('companies.account-groups.create', $company) }}" class="btn btn-primary">
                + Create Group
            </a>
            <a href="{{ route('companies.show', $company) }}" class="btn btn-outline-secondary">
                Back to Company
            </a>
        </div>
    </div>

    @if (session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif
    @if (session('error'))
        <div class="alert alert-danger">{{ session('error') }}</div>
    @endif

    <div class="card border-0 shadow-sm">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Group</th>
                        <th>Under</th>
                        <th>Nature</th>
                        <th class="text-end">Ledgers</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($groups as $group)
                        <tr>
                            <td>
                                <span class="fw-semibold">{{ $group->name }}</span>
                                @if ($group->code)
                                    <span class="badge text-bg-light ms-1">Predefined</span>
                                @endif
                            </td>
                            <td>{{ $group->parent?->name ?? 'Primary' }}</td>
                            <td class="text-capitalize">{{ $group->nature }}</td>
                            <td class="text-end">{{ $group->accounts_count }}</td>
                            <td class="text-end">
                                <div class="btn-group btn-group-sm" role="group">
                                    <a href="{{ route('companies.account-groups.edit', [$company, $group]) }}"
                                        class="btn btn-outline-primary">Edit</a>
                                    @unless ($group->code)
                                        <form method="POST"
                                            action="{{ route('companies.account-groups.destroy', [$company, $group]) }}"
                                            onsubmit="return confirm(@js('Delete group '.$group->name.'?'));">
                                            @csrf
                                            @method('DELETE')
                                            <button class="btn btn-outline-danger btn-sm">Delete</button>
                                        </form>
                                    @endunless
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
