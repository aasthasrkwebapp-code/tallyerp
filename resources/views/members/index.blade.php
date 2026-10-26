@extends('layouts.erp')

@section('title', 'Users & Roles')

@section('content')
<div class="page-header">
    <div>
        <h1>Users &amp; roles</h1>
        <p class="text-secondary mb-0">Who can access {{ $currentCompany->name }} and what they can do.</p>
    </div>
</div>

<div class="row g-3">
    <div class="col-12 col-xl-8">
        <div class="erp-card">
            <div class="erp-card-header">Members</div>
            <div class="table-responsive">
                <table class="table align-middle mb-0 table-stack">
                    <thead class="table-light"><tr><th>User</th><th>Role</th><th>Active</th><th class="text-end">Actions</th></tr></thead>
                    <tbody>
                    @foreach ($members as $member)
                        <tr>
                            <td data-label="User">
                                <div class="fw-semibold">{{ $member->name }}</div>
                                <small class="text-secondary">{{ $member->email }}</small>
                            </td>
                            @can('users.edit')
                                <td data-label="Role" colspan="2">
                                    <form method="POST" action="{{ route('members.update', $member) }}" class="d-flex flex-wrap gap-2 align-items-center" id="member-{{ $member->id }}">
                                        @csrf
                                        @method('PUT')
                                        <label class="visually-hidden" for="role-{{ $member->id }}">Role</label>
                                        <select id="role-{{ $member->id }}" name="role_id" class="form-select form-select-sm w-auto">
                                            @foreach ($roles as $role)
                                                <option value="{{ $role->id }}" @selected($role->id === $member->pivot->role_id)>{{ ucfirst($role->name) }}</option>
                                            @endforeach
                                        </select>
                                        <div class="form-check form-switch mb-0">
                                            <input class="form-check-input" type="checkbox" name="is_active" value="1" id="active-{{ $member->id }}" @checked($member->pivot->is_active)>
                                            <label class="form-check-label small" for="active-{{ $member->id }}">Active</label>
                                        </div>
                                        <button class="btn btn-sm btn-outline-primary">Save</button>
                                    </form>
                                </td>
                            @else
                                <td data-label="Role" class="text-capitalize">{{ $roles->firstWhere('id', $member->pivot->role_id)?->name }}</td>
                                <td data-label="Active">{{ $member->pivot->is_active ? 'Yes' : 'No' }}</td>
                            @endcan
                            <td data-label="" class="text-end">
                                @can('users.delete')
                                    @if ($member->id !== auth()->id())
                                        <form method="POST" action="{{ route('members.destroy', $member) }}" data-confirm="Remove {{ $member->name }} from this company?">
                                            @csrf
                                            @method('DELETE')
                                            <button class="btn btn-sm btn-outline-danger" aria-label="Remove {{ $member->name }}"><i class="bi bi-person-x"></i></button>
                                        </form>
                                    @endif
                                @endcan
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="col-12 col-xl-4">
        @can('users.create')
            <form method="POST" action="{{ route('members.store') }}" class="erp-card p-3 mb-3">
                @csrf
                <h2 class="h6 fw-bold">Add a member</h2>
                <p class="small text-secondary">The person must already have registered an account.</p>
                <div class="mb-2">
                    <label for="member_email" class="form-label">Email</label>
                    <input id="member_email" type="email" name="email" value="{{ old('email') }}" class="form-control" required>
                </div>
                <div class="mb-3">
                    <label for="member_role" class="form-label">Role</label>
                    <select id="member_role" name="role_id" class="form-select" required>
                        @foreach ($roles as $role)
                            <option value="{{ $role->id }}" @selected($role->name === 'accountant')>{{ ucfirst($role->name) }}</option>
                        @endforeach
                    </select>
                </div>
                <button class="btn btn-primary w-100">Add member</button>
            </form>
        @endcan

        <div class="erp-card">
            <div class="erp-card-header d-flex justify-content-between align-items-center">
                Roles
                @can('users.create')<a href="{{ route('roles.create') }}" class="btn btn-sm btn-outline-primary">New role</a>@endcan
            </div>
            <ul class="list-group list-group-flush">
                @foreach ($roles as $role)
                    <li class="list-group-item d-flex justify-content-between align-items-start gap-2">
                        <div>
                            <div class="fw-semibold text-capitalize">{{ $role->name }}
                                @if ($role->is_system)<span class="badge text-bg-light border">System</span>@endif
                            </div>
                            <small class="text-secondary">{{ $role->description }}</small>
                        </div>
                        @if ($role->is_system)
                            <a class="btn btn-sm btn-link" href="{{ route('roles.show', $role) }}">View</a>
                        @else
                            @can('users.edit')<a class="btn btn-sm btn-link" href="{{ route('roles.edit', $role) }}">Edit</a>@endcan
                        @endif
                    </li>
                @endforeach
            </ul>
        </div>
    </div>
</div>
@endsection
