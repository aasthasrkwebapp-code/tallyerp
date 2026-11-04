@extends('layouts.erp')

@section('title', 'Ledgers')

@section('content')
<div class="container-fluid py-3">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
        <div>
            <h2 class="h4 mb-1">Ledgers</h2>
            <p class="text-secondary mb-0">{{ $company->name }}</p>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <a href="{{ route('companies.accounts.create', $company) }}" class="btn btn-primary">
                + Create Ledger
            </a>
            <a href="{{ route('companies.show', $company) }}" class="btn btn-outline-secondary">
                Back to Company
            </a>
        </div>
    </div>

    @if (session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif
    @if (session('error'))
        <div class="alert alert-danger">{{ session('error') }}</div>
    @endif

    <form method="GET" class="row g-2 mb-3">
        <div class="col-12 col-md-5">
            <input name="q" value="{{ request('q') }}" class="form-control" placeholder="Search ledgers">
        </div>
        <div class="col-12 col-md-5">
            <select name="group" class="form-select">
                <option value="">All groups</option>
                @foreach ($groups as $group)
                    <option value="{{ $group->id }}" @selected((string) request('group') === (string) $group->id)>
                        {{ $group->name }}
                    </option>
                @endforeach
            </select>
        </div>
        <div class="col-12 col-md-2 d-grid">
            <button class="btn btn-outline-primary">Filter</button>
        </div>
    </form>

    <div class="card border-0 shadow-sm">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Ledger</th>
                        <th>Under</th>
                        <th class="text-end">Opening Balance</th>
                        <th>Status</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($accounts as $account)
                        <tr>
                            <td>
                                <div class="fw-semibold">{{ $account->name }}</div>
                                @if ($account->code)
                                    <small class="text-secondary">{{ $account->code }}</small>
                                @endif
                            </td>
                            <td>{{ $account->group->name }}</td>
                            <td class="text-end">
                                {{ inr((float) $account->opening_balance, 2) }}
                                {{ strtoupper($account->opening_balance_type) }}
                            </td>
                            <td>
                                <span class="badge {{ $account->is_active ? 'text-bg-success' : 'text-bg-secondary' }}">
                                    {{ $account->is_active ? 'Active' : 'Inactive' }}
                                </span>
                            </td>
                            <td class="text-end">
                                <div class="btn-group btn-group-sm" role="group">
                                    <a href="{{ route('companies.accounts.edit', [$company, $account]) }}"
                                        class="btn btn-outline-primary">Edit</a>
                                    <form method="POST"
                                        action="{{ route('companies.accounts.destroy', [$company, $account]) }}"
                                        onsubmit="return confirm(@js('Delete ledger '.$account->name.'?'));">
                                        @csrf
                                        @method('DELETE')
                                        <button class="btn btn-outline-danger btn-sm">Delete</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="text-center text-secondary py-4">
                                No ledgers yet.
                                <a href="{{ route('companies.accounts.create', $company) }}">Create your first ledger</a>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
