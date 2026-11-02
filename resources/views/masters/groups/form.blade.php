@extends('layouts.erp')

@section('title', $group->exists ? 'Edit Group' : 'New Group')

@section('content')
<div class="page-header">
    <h1>{{ $group->exists ? 'Edit group: '.$group->name : 'New account group' }}</h1>
    <a href="{{ route('account-groups.index') }}" class="btn btn-outline-secondary">Back</a>
</div>

<form method="POST" action="{{ $group->exists ? route('account-groups.update', $group) : route('account-groups.store') }}" class="erp-card p-3 p-md-4" style="max-width: 720px">
    @csrf
    @if ($group->exists) @method('PUT') @endif

    <div class="row g-3">
        <div class="col-12 col-md-8">
            <label for="name" class="form-label">Name <span class="text-danger">*</span></label>
            <input id="name" name="name" value="{{ old('name', $group->name) }}" class="form-control @error('name') is-invalid @enderror" required maxlength="100" autofocus>
            @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <div class="col-12 col-md-4">
            <label for="code" class="form-label">Code</label>
            <input id="code" name="code" value="{{ old('code', $group->code) }}" class="form-control @error('code') is-invalid @enderror" maxlength="50">
            @error('code')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>

        @if ($group->is_system)
            <div class="col-12"><div class="alert alert-light border small mb-0">Under: <strong>{{ $group->parent?->name ?? 'Primary' }}</strong>. The position of predefined groups cannot be changed.</div></div>
        @else
            <div class="col-12">
                <label for="parent_id" class="form-label">Under</label>
                <select id="parent_id" name="parent_id" class="form-select @error('parent_id') is-invalid @enderror">
                    <option value="">Primary (top level)</option>
                    @foreach ($parents as $p)
                        <option value="{{ $p->id }}" @selected((int) old('parent_id', $group->parent_id) === $p->id)>{{ str_repeat('— ', $p->depth) }}{{ $p->name }}</option>
                    @endforeach
                </select>
                @error('parent_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                <div class="form-text">Sub-groups take the nature of the group they are under.</div>
            </div>
            <div class="col-12 col-md-6">
                <label for="nature" class="form-label">Nature (primary groups only)</label>
                <select id="nature" name="nature" class="form-select @error('nature') is-invalid @enderror">
                    <option value="">—</option>
                    @foreach (\App\Models\AccountGroup::NATURES as $key => $label)
                        <option value="{{ $key }}" @selected(old('nature', $group->exists && ! $group->parent_id ? $group->nature : null) === $key)>{{ $label }}</option>
                    @endforeach
                </select>
                @error('nature')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="col-12 col-md-6 d-flex align-items-end">
                <div class="form-check mb-2">
                    <input type="checkbox" class="form-check-input" id="affects_gross_profit" name="affects_gross_profit" value="1" @checked(old('affects_gross_profit', $group->affects_gross_profit))>
                    <label for="affects_gross_profit" class="form-check-label">Affects gross profit (trading account)</label>
                </div>
            </div>
        @endif

        <div class="col-12">
            <label for="description" class="form-label">Description</label>
            <input id="description" name="description" value="{{ old('description', $group->description) }}" class="form-control" maxlength="255">
        </div>
    </div>

    <button class="btn btn-primary mt-4" data-shortcut="s">Save group</button>
</form>
@endsection
