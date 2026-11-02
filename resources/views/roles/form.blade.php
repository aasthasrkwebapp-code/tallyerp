@extends('layouts.erp')

@php($readonly = $readonly ?? false)
@section('title', $role->exists ? 'Role: '.ucfirst($role->name) : 'New Role')

@section('content')
<div class="page-header">
    <div>
        <h1>{{ $role->exists ? 'Role: '.ucfirst($role->name) : 'New role' }}</h1>
        @if ($readonly)<p class="text-secondary mb-0">System roles are read-only. Create a custom role to change permissions.</p>@endif
    </div>
    <a href="{{ route('members.index') }}" class="btn btn-outline-secondary">Back</a>
</div>

<form method="POST" action="{{ $role->exists ? route('roles.update', $role) : route('roles.store') }}" class="erp-card p-3 p-md-4">
    @csrf
    @if ($role->exists) @method('PUT') @endif
    <fieldset @disabled($readonly)>
        <div class="row g-3 mb-3">
            <div class="col-12 col-md-4">
                <label for="name" class="form-label">Role name</label>
                <input id="name" name="name" class="form-control" value="{{ old('name', $role->name) }}" required maxlength="50">
            </div>
            <div class="col-12 col-md-8">
                <label for="description" class="form-label">Description</label>
                <input id="description" name="description" class="form-control" value="{{ old('description', $role->description) }}" maxlength="255">
            </div>
        </div>

        <h2 class="h6 fw-bold">Permissions</h2>
        <div class="table-responsive">
            <table class="table table-sm align-middle">
                <thead class="table-light"><tr><th>Module</th><th>Actions</th></tr></thead>
                <tbody>
                @foreach ($permissions as $module => $items)
                    <tr>
                        <td class="fw-semibold small">{{ config("erp.modules.$module.label") }}</td>
                        <td>
                            <div class="d-flex flex-wrap gap-3">
                                @foreach ($items as $permission)
                                    <div class="form-check mb-0">
                                        <input class="form-check-input" type="checkbox" name="permissions[]" value="{{ $permission->id }}" id="perm-{{ $permission->id }}"
                                               @checked(in_array($permission->id, old('permissions', $granted)))>
                                        <label class="form-check-label small text-capitalize" for="perm-{{ $permission->id }}">{{ $permission->action }}</label>
                                    </div>
                                @endforeach
                            </div>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </fieldset>

    @unless ($readonly)
        <button class="btn btn-primary" data-shortcut="s">Save role</button>
    @endunless
</form>

@if ($role->exists && ! $readonly)
    <form method="POST" action="{{ route('roles.destroy', $role) }}" class="mt-3" data-confirm="Delete this role?">
        @csrf
        @method('DELETE')
        <button class="btn btn-outline-danger btn-sm">Delete role</button>
    </form>
@endif
@endsection
