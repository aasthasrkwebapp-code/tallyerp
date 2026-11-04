@extends('layouts.erp')

@section('title', $group->exists ? 'Edit Group' : 'Create Group')

@section('content')
<div class="container-fluid py-3">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
        <div>
            <h2 class="h4 mb-1">{{ $group->exists ? 'Edit Group' : 'Create Group' }}</h2>
            <p class="text-secondary mb-0">{{ $company->name }}</p>
        </div>
        <a href="{{ route('companies.account-groups.index', $company) }}" class="btn btn-outline-secondary">
            Back to Groups
        </a>
    </div>

    @if ($errors->any())
        <div class="alert alert-danger">
            Please correct the errors below.
            <ul class="mb-0 mt-2">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="POST"
        action="{{ $group->exists
            ? route('companies.account-groups.update', [$company, $group])
            : route('companies.account-groups.store', $company) }}">
        @csrf
        @if ($group->exists)
            @method('PUT')
        @endif

        <div class="card border-0 shadow-sm">
            <div class="card-body p-3 p-md-4">
                <div class="row g-3">
                    <div class="col-12 col-md-6">
                        <label class="form-label">Name *</label>
                        <input name="name" value="{{ old('name', $group->name) }}"
                            class="form-control" required maxlength="255">
                    </div>

                    <div class="col-12 col-md-6">
                        <label class="form-label">Under *</label>
                        @if ($group->code)
                            <input class="form-control" value="{{ $group->parent?->name ?? 'Primary' }}" disabled>                            <div class="form-text">Predefined groups keep their position.</div>
                        @else
                            <select name="parent_id" class="form-select" required>
                                <option value="">Select group</option>
                                @foreach ($parents as $parent)
                                    <option value="{{ $parent->id }}"
                                        @selected((string) old('parent_id', $group->parent_id) === (string) $parent->id)>
                                        {{ $parent->name }}
                                    </option>
                                @endforeach
                            </select>
                            <div class="form-text">The group's nature (asset, liability, income or expense) comes from this.</div>
                        @endif
                    </div>
                </div>
            </div>
            <div class="card-footer bg-white text-end">
                <button class="btn btn-primary">Save Group</button>
            </div>
        </div>
    </form>
</div>
@endsection
