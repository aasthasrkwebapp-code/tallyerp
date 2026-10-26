@extends('layouts.erp')

@section('title', 'Pay Heads')

@php($r = $editing)
@php($v = fn ($name, $default = null) => old($name, $r?->{$name} ?? $default))

@section('content')
<div class="page-header">
    <div>
        <h1>Pay heads</h1>
        <p class="text-secondary mb-0">Earnings, deductions and employer contributions, and the ledgers they post to.</p>
    </div>
</div>

<div class="row g-3">
    <div class="col-12 col-xl-8">
        <div class="erp-card">
            <div class="table-responsive">
                <table class="table align-middle mb-0 table-stack">
                    <thead class="table-light"><tr><th>Pay head</th><th>Type</th><th>Calculation</th><th>Ledger</th><th class="text-end">Employees</th><th class="text-end">Actions</th></tr></thead>
                    <tbody>
                    @foreach ($heads as $h)
                        <tr>
                            <td data-label="Pay head" class="fw-semibold">{{ $h->name }}
                                @if ($h->is_basic)<span class="badge text-bg-primary">Basic</span>@endif
                                @unless ($h->is_active)<span class="badge text-bg-secondary">Inactive</span>@endunless
                            </td>
                            <td data-label="Type">{{ \App\Models\PayHead::TYPES[$h->type] }}</td>
                            <td data-label="Calculation" class="small">{{ \App\Models\PayHead::CALCULATIONS[$h->calculation] }}@if ($h->rate !== null) · {{ \App\Support\Decimal::trim($h->rate) }}%@endif @if ($h->prorate)<span class="text-secondary">· by paid days</span>@endif</td>
                            <td data-label="Ledger" class="small">{{ $h->account->name }}@if ($h->payableAccount) → {{ $h->payableAccount->name }}@endif</td>
                            <td data-label="Employees" class="text-end">{{ $usage[$h->id] ?? 0 }}</td>
                            <td data-label="" class="text-end text-nowrap">
                                @can('payroll.edit')<a href="{{ route('pay-heads.index', ['edit' => $h->id]) }}" class="btn btn-sm btn-outline-secondary">Edit</a>@endcan
                                @can('payroll.delete')
                                    <form method="POST" action="{{ route('pay-heads.destroy', $h) }}" class="d-inline" data-confirm="Delete pay head {{ $h->name }}?">
                                        @csrf @method('DELETE')
                                        <button class="btn btn-sm btn-outline-danger" aria-label="Delete {{ $h->name }}"><i class="bi bi-trash"></i></button>
                                    </form>
                                @endcan
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <div class="col-12 col-xl-4">
        @if ($r ? auth()->user()->can('payroll.edit') : auth()->user()->can('payroll.create'))
            <form method="POST" action="{{ $r ? route('pay-heads.update', $r) : route('pay-heads.store') }}" class="erp-card p-3">
                @csrf
                @if ($r) @method('PUT') @endif
                <h2 class="h6 fw-bold">{{ $r ? 'Edit '.$r->name : 'New pay head' }}</h2>
                <div class="mb-2">
                    <label for="name" class="form-label">Name</label>
                    <input id="name" name="name" value="{{ $v('name') }}" class="form-control @error('name') is-invalid @enderror" required maxlength="100">
                    @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="row g-2 mb-2">
                    <div class="col-6">
                        <label for="type" class="form-label">Type</label>
                        <select id="type" name="type" class="form-select">
                            @foreach (\App\Models\PayHead::TYPES as $k => $label)<option value="{{ $k }}" @selected($v('type', 'earning') === $k)>{{ $label }}</option>@endforeach
                        </select>
                    </div>
                    <div class="col-6">
                        <label for="rate" class="form-label">Rate %</label>
                        <input id="rate" name="rate" value="{{ $v('rate') !== null ? \App\Support\Decimal::trim($v('rate')) : '' }}" class="form-control text-end @error('rate') is-invalid @enderror" inputmode="decimal">
                        @error('rate')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                </div>
                <div class="mb-2">
                    <label for="calculation" class="form-label">Calculation</label>
                    <select id="calculation" name="calculation" class="form-select @error('calculation') is-invalid @enderror">
                        @foreach (\App\Models\PayHead::CALCULATIONS as $k => $label)<option value="{{ $k }}" @selected($v('calculation', 'flat') === $k)>{{ $label }}</option>@endforeach
                    </select>
                    @error('calculation')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="mb-2">
                    <label for="account_id" class="form-label">Ledger <small class="text-secondary">(expense for earnings and employer; payable for deductions)</small></label>
                    <select id="account_id" name="account_id" class="form-select @error('account_id') is-invalid @enderror" required>
                        <optgroup label="Expense ledgers">@foreach ($expenseLedgers as $a)<option value="{{ $a->id }}" @selected((int) $v('account_id') === $a->id)>{{ $a->name }}</option>@endforeach</optgroup>
                        <optgroup label="Liability ledgers">@foreach ($liabilityLedgers as $a)<option value="{{ $a->id }}" @selected((int) $v('account_id') === $a->id)>{{ $a->name }}</option>@endforeach</optgroup>
                    </select>
                    @error('account_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="mb-2">
                    <label for="payable_account_id" class="form-label">Payable to <small class="text-secondary">(employer contributions)</small></label>
                    <select id="payable_account_id" name="payable_account_id" class="form-select @error('payable_account_id') is-invalid @enderror">
                        <option value="">—</option>
                        @foreach ($liabilityLedgers as $a)<option value="{{ $a->id }}" @selected((int) $v('payable_account_id') === $a->id)>{{ $a->name }}</option>@endforeach
                    </select>
                    @error('payable_account_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                @foreach (['prorate' => 'Reduce by unpaid days', 'is_basic' => 'This is the basic salary', 'is_active' => 'Active'] as $flag => $label)
                    <div class="form-check">
                        <input type="hidden" name="{{ $flag }}" value="0">
                        <input class="form-check-input @error($flag) is-invalid @enderror" type="checkbox" name="{{ $flag }}" value="1" id="{{ $flag }}" @checked($v($flag, $flag !== 'is_basic'))>
                        <label class="form-check-label" for="{{ $flag }}">{{ $label }}</label>
                        @error($flag)<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                @endforeach
                <div class="d-flex gap-2 mt-3">
                    <button class="btn btn-primary flex-grow-1">{{ $r ? 'Save changes' : 'Add pay head' }}</button>
                    @if ($r)<a href="{{ route('pay-heads.index') }}" class="btn btn-outline-secondary">Cancel</a>@endif
                </div>
            </form>
        @endif
    </div>
</div>
@endsection
