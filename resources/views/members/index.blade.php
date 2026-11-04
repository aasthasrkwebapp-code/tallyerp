@extends('layouts.erp')

@section('title', 'Users & Roles')

@section('content')
<div class="container-fluid py-3">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
        <div>
            <h2 class="h4 mb-1">Users &amp; Roles</h2>
            <p class="text-secondary mb-0">{{ $company->name }} · owned by {{ $company->user->name }}</p>
        </div>
        <a href="{{ route('companies.show', $company) }}" class="btn btn-outline-secondary">Back to Company</a>
    </div>

    @if (session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif
    @if ($errors->any())
        <div class="alert alert-danger">
            <ul class="mb-0">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="card border-0 shadow-sm mb-3">
        <div class="card-header bg-white fw-semibold">Give someone access</div>
        <div class="card-body">
            <form method="POST" action="{{ route('companies.members.store', $company) }}" class="row g-2">
                @csrf
                <div class="col-12 col-md-6">
                    <input type="email" name="email" value="{{ old('email') }}" class="form-control" placeholder="Their login email" required>
                </div>
                <div class="col-8 col-md-4">
                    <select name="role_id" class="form-select" aria-label="Role" required>
                        @foreach ($roles as $role)
                            <option value="{{ $role->id }}" @selected((string) old('role_id') === (string) $role->id)>{{ ucfirst($role->name) }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-4 col-md-2 d-grid"><button class="btn btn-primary">Add</button></div>
            </form>
            <div class="form-text">They need to have registered already.</div>
        </div>
    </div>

    <div class="card border-0 shadow-sm mb-3">
        <div class="table-responsive">
            <table class="table align-middle mb-0">
                <thead class="table-light">
                    <tr><th>User</th><th style="width: 260px">Role</th><th></th></tr>
                </thead>
                <tbody>
                    <tr>
                        <td><div class="fw-semibold">{{ $company->user->name }}</div><small class="text-secondary">{{ $company->user->email }}</small></td>
                        <td>Owner</td>
                        <td></td>
                    </tr>
                    @foreach ($members as $member)
                        <tr>
                            <td><div class="fw-semibold">{{ $member->name }}</div><small class="text-secondary">{{ $member->email }}</small></td>
                            <td>
                                <form method="POST" action="{{ route('companies.members.update', [$company, $member]) }}" class="d-flex gap-2">
                                    @csrf
                                    @method('PUT')
                                    <select name="role_id" class="form-select form-select-sm" aria-label="Role for {{ $member->name }}">
                                        @foreach ($roles as $role)
                                            <option value="{{ $role->id }}" @selected((int) $member->pivot->role_id === (int) $role->id)>{{ ucfirst($role->name) }}</option>
                                        @endforeach
                                    </select>
                                    <button class="btn btn-sm btn-outline-primary">Save</button>
                                </form>
                            </td>
                            <td class="text-end">
                                <form method="POST" action="{{ route('companies.members.destroy', [$company, $member]) }}"
                                    onsubmit="return confirm(@js('Remove '.$member->name.'’s access?'));">
                                    @csrf
                                    @method('DELETE')
                                    <button class="btn btn-sm btn-outline-danger">Remove</button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white fw-semibold">What each role can use</div>
        <div class="table-responsive">
            <table class="table table-sm mb-0 small">
                <tbody>
                    @foreach (\App\Support\Access::ROLE_MODULES as $role => $modules)
                        <tr>
                            <td class="fw-semibold" style="width: 140px">{{ ucfirst($role) }}</td>
                            <td>
                                {{ in_array('*', $modules) ? 'Everything' : implode(', ', array_map(fn ($m) => $m === 'tds' ? 'TDS' : ucfirst($m), $modules)) }}
                                @if (in_array($role, \App\Support\Access::READ_ONLY))
                                    <span class="badge text-bg-light">read-only</span>
                                @endif
                                @if ($role === 'admin')
                                    <span class="text-secondary">(except deleting the company)</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
