@extends('layouts.erp')

@section('title', 'Ledgers')

@section('content')
<div class="page-header">
    <div>
        <h1>Ledgers</h1>
        <p class="text-secondary mb-0">Accounts used in vouchers. Opening balances are entered here.</p>
    </div>
    @can('masters.create')
        <a href="{{ route('ledgers.create') }}" class="btn btn-primary" data-shortcut="c"><i class="bi bi-plus-lg me-1"></i>New ledger</a>
    @endcan
</div>

@if ($openingDifference !== 0)
    <div class="alert alert-warning">
        <i class="bi bi-exclamation-triangle me-1"></i>
        Difference in opening balances: <strong>{{ \App\Support\Money::drCr($openingDifference) }}</strong>.
        Total debit and credit opening balances should be equal.
    </div>
@endif

<form method="GET" class="erp-card p-3 mb-3">
    <div class="row g-2 align-items-end">
        <div class="col-12 col-md-4">
            <label class="form-label small" for="q">Search</label>
            <input id="q" name="q" value="{{ $filters['q'] ?? '' }}" class="form-control form-control-sm" placeholder="Name, alias or code">
        </div>
        <div class="col-6 col-md-3">
            <label class="form-label small" for="group">Group</label>
            <select id="group" name="group" class="form-select form-select-sm">
                <option value="">All groups</option>
                @foreach ($groups as $g)
                    <option value="{{ $g->id }}" @selected((int) ($filters['group'] ?? 0) === $g->id)>{{ str_repeat('— ', $g->depth) }}{{ $g->name }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-6 col-md-2">
            <label class="form-label small" for="type">Type</label>
            <select id="type" name="type" class="form-select form-select-sm">
                <option value="">All</option>
                @foreach (\App\Models\Account::TYPES as $k => $label)<option value="{{ $k }}" @selected(($filters['type'] ?? '') === $k)>{{ $label }}</option>@endforeach
            </select>
        </div>
        <div class="col-6 col-md-2">
            <div class="form-check">
                <input class="form-check-input" type="checkbox" name="inactive" value="1" id="inactive" @checked($filters['inactive'] ?? false)>
                <label class="form-check-label small" for="inactive">Include inactive</label>
            </div>
        </div>
        <div class="col-6 col-md-1"><button class="btn btn-sm btn-primary w-100">Filter</button></div>
    </div>
</form>

<div class="erp-card">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0 table-stack">
            <thead class="table-light"><tr><th>Ledger</th><th>Group</th><th>Type</th><th class="text-end">Opening balance</th><th class="text-end">Actions</th></tr></thead>
            <tbody>
            @forelse ($ledgers as $ledger)
                <tr class="{{ $ledger->is_active ? '' : 'text-secondary' }}">
                    <td data-label="Ledger">
                        <span class="fw-semibold">{{ $ledger->name }}</span>
                        @if ($ledger->alias)<small class="text-secondary">({{ $ledger->alias }})</small>@endif
                        @unless ($ledger->is_active)<span class="badge text-bg-secondary">Inactive</span>@endunless
                    </td>
                    <td data-label="Group">{{ $ledger->group->name }}</td>
                    <td data-label="Type">{{ \App\Models\Account::TYPES[$ledger->account_type] ?? $ledger->account_type }}</td>
                    <td data-label="Opening" class="amount">{{ \App\Support\Money::drCr($ledger->openingPaise()) }}</td>
                    <td data-label="" class="text-end text-nowrap">
                        @if (Route::has('reports.ledger'))
                            <a href="{{ route('reports.ledger', ['ledger' => $ledger->id]) }}" class="btn btn-sm btn-outline-secondary" aria-label="Statement of {{ $ledger->name }}"><i class="bi bi-book"></i></a>
                        @endif
                        @can('masters.edit')
                            <a href="{{ route('ledgers.edit', $ledger) }}" class="btn btn-sm btn-outline-primary" aria-label="Edit {{ $ledger->name }}"><i class="bi bi-pencil"></i></a>
                        @endcan
                        @can('masters.delete')
                            @unless ($ledger->is_system)
                                <form method="POST" action="{{ route('ledgers.destroy', $ledger) }}" class="d-inline" data-confirm="Delete ledger {{ $ledger->name }}?">
                                    @csrf @method('DELETE')
                                    <button class="btn btn-sm btn-outline-danger" aria-label="Delete {{ $ledger->name }}"><i class="bi bi-trash"></i></button>
                                </form>
                            @endunless
                        @endcan
                    </td>
                </tr>
            @empty
                <tr><td colspan="5" class="text-center text-secondary py-4">No ledgers found.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
<div class="mt-3">{{ $ledgers->links() }}</div>
@endsection
