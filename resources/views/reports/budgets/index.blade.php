@extends('layouts.erp')

@section('title', 'Budgets')

@section('content')
<div class="page-header">
    <div>
        <h1>Budgets</h1>
        <p class="text-secondary mb-0">Plan amounts for groups and ledgers, optionally for a cost centre, and compare them with the books.</p>
    </div>
    @can('masters.create')
        <a href="{{ route('budgets.create') }}" class="btn btn-primary" data-shortcut="c"><i class="bi bi-plus-lg me-1"></i>New budget</a>
    @endcan
</div>

<div class="erp-card">
    <div class="table-responsive">
        <table class="table align-middle mb-0 table-stack">
            <thead class="table-light"><tr><th>Name</th><th>Period</th><th class="text-end">Lines</th><th>Status</th></tr></thead>
            <tbody>
            @forelse ($budgets as $b)
                <tr>
                    <td data-label="Name"><a href="{{ route('budgets.show', $b) }}" class="fw-semibold">{{ $b->name }}</a>@if ($b->description)<div class="small text-secondary">{{ \Illuminate\Support\Str::limit($b->description, 80) }}</div>@endif</td>
                    <td data-label="Period">{{ $b->from_date->format('d-m-Y') }} to {{ $b->to_date->format('d-m-Y') }}</td>
                    <td data-label="Lines" class="text-end">{{ $b->lines_count }}</td>
                    <td data-label="Status">
                        @if ($b->to_date->toDateString() < $today)<span class="badge text-bg-light border">Ended</span>
                        @elseif ($b->from_date->toDateString() > $today)<span class="badge text-bg-info">Not started</span>
                        @else<span class="badge text-bg-success">Running</span>@endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="4" class="text-center text-secondary py-4">No budgets yet.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
