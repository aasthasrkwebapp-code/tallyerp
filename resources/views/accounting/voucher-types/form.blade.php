@extends('layouts.erp')

@section('title', $type->exists ? 'Edit Voucher Type' : 'New Voucher Type')

@section('content')
<div class="page-header">
    <h1>{{ $type->exists ? 'Edit voucher type: '.$type->name : 'New voucher type' }}</h1>
    <a href="{{ route('voucher-types.index') }}" class="btn btn-outline-secondary">Back</a>
</div>

@php($classLocked = $type->exists && ($type->is_system || ($type->vouchers_count ?? 0) > 0))
<form method="POST" action="{{ $type->exists ? route('voucher-types.update', $type) : route('voucher-types.store') }}" class="erp-card p-3 p-md-4" style="max-width: 760px">
    @csrf
    @if ($type->exists) @method('PUT') @endif
    <div class="row g-3">
        <div class="col-12 col-md-6">
            <label for="name" class="form-label">Name <span class="text-danger">*</span></label>
            <input id="name" name="name" value="{{ old('name', $type->name) }}" class="form-control @error('name') is-invalid @enderror" required maxlength="100">
            @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <div class="col-12 col-md-6">
            <label for="base_type" class="form-label">Voucher class</label>
            <select id="base_type" name="base_type" class="form-select" @disabled($classLocked)>
                @foreach (config('erp.voucher_base_types') as $key => $label)
                    <option value="{{ $key }}" @selected(old('base_type', $type->base_type) === $key)>{{ $label }}</option>
                @endforeach
            </select>
            @if ($classLocked)<input type="hidden" name="base_type" value="{{ $type->base_type }}"><div class="form-text">Fixed for predefined or used types.</div>@endif
        </div>
        <div class="col-6 col-md-4">
            <label for="numbering" class="form-label">Numbering</label>
            <select id="numbering" name="numbering" class="form-select">
                <option value="auto" @selected(old('numbering', $type->numbering) === 'auto')>Automatic</option>
                <option value="manual" @selected(old('numbering', $type->numbering) === 'manual')>Manual</option>
            </select>
        </div>
        <div class="col-6 col-md-4">
            <label for="prefix" class="form-label">Prefix</label>
            <input id="prefix" name="prefix" value="{{ old('prefix', $type->prefix) }}" class="form-control @error('prefix') is-invalid @enderror" maxlength="20">
            @error('prefix')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <div class="col-6 col-md-4">
            <label for="suffix" class="form-label">Suffix</label>
            <input id="suffix" name="suffix" value="{{ old('suffix', $type->suffix) }}" class="form-control @error('suffix') is-invalid @enderror" maxlength="20">
            @error('suffix')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <div class="col-6 col-md-4">
            <label for="number_width" class="form-label">Digits (zero-padded)</label>
            <input id="number_width" type="number" min="1" max="10" name="number_width" value="{{ old('number_width', $type->number_width) }}" class="form-control">
        </div>
        <div class="col-6 col-md-4">
            <label for="starting_number" class="form-label">Starting number</label>
            <input id="starting_number" type="number" min="1" name="starting_number" value="{{ old('starting_number', $type->starting_number) }}" class="form-control">
        </div>
        <div class="col-12 col-md-4 d-flex align-items-end">
            <div class="form-check mb-2">
                <input type="checkbox" class="form-check-input" id="restart_yearly" name="restart_yearly" value="1" @checked(old('restart_yearly', $type->restart_yearly))>
                <label class="form-check-label" for="restart_yearly">Restart numbering each financial year</label>
            </div>
        </div>
        <div class="col-12">
            <label for="default_narration" class="form-label">Default narration</label>
            <input id="default_narration" name="default_narration" value="{{ old('default_narration', $type->default_narration) }}" class="form-control" maxlength="255">
        </div>
        @unless ($type->is_system)
            <div class="col-12">
                <div class="form-check form-switch">
                    <input type="checkbox" class="form-check-input" id="is_active" name="is_active" value="1" @checked(old('is_active', $type->is_active))>
                    <label class="form-check-label" for="is_active">Active</label>
                </div>
            </div>
        @else
            <input type="hidden" name="is_active" value="1">
        @endunless
    </div>
    <button class="btn btn-primary mt-4" data-shortcut="s">Save voucher type</button>
</form>
@endsection
