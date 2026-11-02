@extends('layouts.erp')

@section('title', 'Opening Leave Balances')

@php($canEdit = auth()->user()->can('payroll.edit'))

@section('content')
<div class="page-header">
    <div>
        <h1>Opening leave balances</h1>
        <p class="text-secondary mb-0">Unused leave of each employee when the books began{{ $begin ? ' on '.$begin->format('d M Y') : '' }}. It is carried into that year's leave register.</p>
    </div>
    <div class="d-flex flex-wrap gap-2 no-print">
        <a href="{{ route('leave-register', ['date' => $begin?->toDateString()]) }}" class="btn btn-outline-secondary">Leave register</a>
        <a href="{{ route('leave-types.index') }}" class="btn btn-outline-secondary">Leave types</a>
    </div>
</div>

@if ($types->isEmpty())
    <div class="alert alert-info">Only leave types with a yearly allowance keep a balance. Set an allowance on a <a href="{{ route('leave-types.index') }}">leave type</a> first.</div>
@else
<form method="POST" action="{{ route('leave-openings.update') }}" class="erp-card">
    @csrf @method('PUT')
    <div class="table-responsive">
        <table class="table table-sm align-middle mb-0 table-stack">
            <thead class="table-light"><tr><th>Employee</th>@foreach ($types as $t)<th class="text-end">{{ $t->name }} ({{ $t->code }})</th>@endforeach</tr></thead>
            <tbody>
            @forelse ($employees as $e)
                <tr>
                    <td data-label="Employee"><a href="{{ route('employees.show', $e) }}">{{ $e->name }}</a> <span class="text-secondary small">{{ $e->code }}</span></td>
                    @foreach ($types as $t)
                        @php($key = "days.{$e->id}.{$t->id}")
                        <td data-label="{{ $t->code }}" class="text-end">
                            <input name="days[{{ $e->id }}][{{ $t->id }}]" value="{{ old($key, $openings[$e->id.'|'.$t->id] ?? '') }}" inputmode="decimal" placeholder="0"
                                class="form-control form-control-sm text-end ms-auto @error($key) is-invalid @enderror" style="max-width: 7rem" aria-label="{{ $e->name }} {{ $t->name }}" @disabled(! $canEdit)>
                            @error($key)<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </td>
                    @endforeach
                </tr>
            @empty
                <tr><td colspan="{{ 1 + $types->count() }}" class="text-center text-secondary py-4">No employees yet.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    @if ($canEdit && $employees->isNotEmpty())
        <div class="p-3"><button class="btn btn-primary" data-shortcut="s">Save opening balances</button></div>
    @endif
</form>
<p class="small text-secondary mt-2">Enter days in whole or half days; leave blank or 0 for none. For leave types that carry forward, the opening balance and each later year's unused leave roll on up to the type's limit; for other types it counts in the first year only.</p>
@endif
@endsection
