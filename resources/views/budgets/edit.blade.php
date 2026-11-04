@extends('layouts.erp')

@section('title', 'Set Budgets')

@section('content')
<div class="container-fluid py-3">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
        <div>
            <h2 class="h4 mb-1">Set Budgets · FY {{ $company->financial_year }}</h2>
            <p class="text-secondary mb-0">{{ $company->name }}</p>
        </div>
        <a href="{{ route('companies.budgets.index', $company) }}" class="btn btn-outline-secondary">Budget vs Actual</a>
    </div>

    @if ($errors->any())
        <div class="alert alert-danger">
            <ul class="mb-0">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @if ($accounts->isEmpty())
        <div class="alert alert-warning">
            Create income and expense ledgers first. <a href="{{ route('companies.accounts.create', $company) }}">Create ledger</a>
        </div>
    @else
        <form method="POST" action="{{ route('companies.budgets.update', $company) }}">
            @csrf
            @method('PUT')
            <div class="card border-0 shadow-sm">
                <div class="table-responsive">
                    <table class="table align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Ledger</th>
                                <th>Group</th>
                                <th class="text-end">Last year actual</th>
                                <th style="width: 200px" class="text-end">Budget for the year</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($accounts as $account)
                                <tr>
                                    <td>{{ $account->name }}</td>
                                    <td class="text-secondary">{{ $account->group->name }}</td>
                                    <td class="text-end text-secondary">{{ isset($lastYear[$account->id]) ? inr($lastYear[$account->id]) : '—' }}</td>
                                    <td>
                                        <input type="number" step="0.01" min="0" name="budgets[{{ $account->id }}]"
                                            value="{{ old('budgets.'.$account->id, isset($budgets[$account->id]) ? (float) $budgets[$account->id] : '') }}"
                                            class="form-control text-end" aria-label="Budget for {{ $account->name }}">
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="card-footer bg-white d-flex justify-content-between align-items-center">
                    <small class="text-secondary">Leave blank for no budget.</small>
                    <button class="btn btn-primary">Save Budgets</button>
                </div>
            </div>
        </form>
    @endif
</div>
@endsection
