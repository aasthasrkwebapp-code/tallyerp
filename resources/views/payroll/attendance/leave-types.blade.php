@extends('layouts.erp')

@section('title', 'Leave Types')

@php($r = $editing)
@php($v = fn ($name, $default = null) => old($name, $r?->{$name} ?? $default))

@section('content')
<div class="page-header">
    <div>
        <h1>Leave types</h1>
        <p class="text-secondary mb-0">Kinds of leave marked in the attendance register. Leave without pay reduces paid days.</p>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <a href="{{ route('attendance.index') }}" class="btn btn-outline-secondary">Attendance</a>
        <a href="{{ route('leave-register') }}" class="btn btn-outline-secondary">Leave register</a>
    </div>
</div>

<div class="row g-3">
    <div class="col-12 col-xl-8">
        <div class="erp-card">
            <div class="table-responsive">
                <table class="table align-middle mb-0 table-stack">
                    <thead class="table-light"><tr><th>Leave type</th><th>Code</th><th>Pay</th><th class="text-end">Days a year</th><th class="text-end">Days marked</th><th class="text-end">Actions</th></tr></thead>
                    <tbody>
                    @forelse ($types as $t)
                        <tr>
                            <td data-label="Leave type" class="fw-semibold">{{ $t->name }} @unless ($t->is_active)<span class="badge text-bg-secondary">Inactive</span>@endunless</td>
                            <td data-label="Code">{{ $t->code }}</td>
                            <td data-label="Pay">{{ $t->is_paid ? 'Paid' : 'Without pay' }}</td>
                            <td data-label="Days a year" class="text-end">{{ $t->annual_days !== null ? \App\Support\Decimal::trim($t->annual_days) : 'No limit' }}</td>
                            <td data-label="Days marked" class="text-end">{{ $usage[$t->id] ?? 0 }}</td>
                            <td data-label="" class="text-end text-nowrap">
                                @can('payroll.edit')<a href="{{ route('leave-types.index', ['edit' => $t->id]) }}" class="btn btn-sm btn-outline-secondary">Edit</a>@endcan
                                @can('payroll.delete')
                                    <form method="POST" action="{{ route('leave-types.destroy', $t) }}" class="d-inline" data-confirm="Delete leave type {{ $t->name }}?">
                                        @csrf @method('DELETE')
                                        <button class="btn btn-sm btn-outline-danger" aria-label="Delete {{ $t->name }}"><i class="bi bi-trash"></i></button>
                                    </form>
                                @endcan
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="text-center text-secondary py-3">No leave types yet.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <div class="col-12 col-xl-4">
        @if ($r ? auth()->user()->can('payroll.edit') : auth()->user()->can('payroll.create'))
            <form method="POST" action="{{ $r ? route('leave-types.update', $r) : route('leave-types.store') }}" class="erp-card p-3">
                @csrf
                @if ($r) @method('PUT') @endif
                <h2 class="h6 fw-bold">{{ $r ? 'Edit '.$r->name : 'New leave type' }}</h2>
                <div class="mb-2">
                    <label for="name" class="form-label">Name</label>
                    <input id="name" name="name" value="{{ $v('name') }}" class="form-control @error('name') is-invalid @enderror" required maxlength="50">
                    @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="row g-2 mb-2">
                    <div class="col-6">
                        <label for="code" class="form-label">Code</label>
                        <input id="code" name="code" value="{{ $v('code') }}" class="form-control text-uppercase @error('code') is-invalid @enderror" required maxlength="10">
                        @error('code')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-6">
                        <label for="annual_days" class="form-label">Days a year</label>
                        <input id="annual_days" name="annual_days" value="{{ $v('annual_days') !== null ? \App\Support\Decimal::trim($v('annual_days')) : '' }}" class="form-control text-end @error('annual_days') is-invalid @enderror" inputmode="decimal" placeholder="No limit">
                        @error('annual_days')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                </div>
                @foreach (['is_paid' => 'Paid leave', 'is_active' => 'Active'] as $flag => $label)
                    <div class="form-check">
                        <input type="hidden" name="{{ $flag }}" value="0">
                        <input class="form-check-input @error($flag) is-invalid @enderror" type="checkbox" name="{{ $flag }}" value="1" id="{{ $flag }}" @checked($v($flag, true))>
                        <label class="form-check-label" for="{{ $flag }}">{{ $label }}</label>
                        @error($flag)<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                @endforeach
                <div class="d-flex gap-2 mt-3">
                    <button class="btn btn-primary flex-grow-1">{{ $r ? 'Save changes' : 'Add leave type' }}</button>
                    @if ($r)<a href="{{ route('leave-types.index') }}" class="btn btn-outline-secondary">Cancel</a>@endif
                </div>
            </form>
        @endif
    </div>
</div>
@endsection
