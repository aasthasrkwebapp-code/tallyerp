@extends('layouts.erp')

@section('title', $employee->exists ? 'Edit Employee' : 'New Employee')

@php($v = fn ($name, $default = null) => old($name, $employee->{$name} instanceof \DateTimeInterface ? $employee->{$name}->toDateString() : ($employee->{$name} ?? $default)))

@section('content')
<div class="page-header">
    <h1>{{ $employee->exists ? 'Edit employee: '.$employee->name : 'New employee' }}</h1>
    <a href="{{ $employee->exists ? route('employees.show', $employee) : route('employees.index') }}" class="btn btn-outline-secondary">Back</a>
</div>

<form method="POST" action="{{ $employee->exists ? route('employees.update', $employee) : route('employees.store') }}" class="erp-card p-3 p-md-4" style="max-width: 1000px">
    @csrf
    @if ($employee->exists) @method('PUT') @endif

    <div class="row g-3">
        @foreach ([
            ['code', 'Employee code', 'required maxlength=30', 'col-6 col-md-3'],
            ['name', 'Full name', 'required maxlength=150', 'col-12 col-md-5'],
            ['designation', 'Designation', 'maxlength=100', 'col-6 col-md-4'],
            ['department', 'Department', 'maxlength=100 list=departments', 'col-6 col-md-4'],
            ['date_of_joining', 'Date of joining', 'required', 'col-6 col-md-4', 'date'],
            ['date_of_leaving', 'Date of leaving', '', 'col-6 col-md-4', 'date'],
            ['date_of_birth', 'Date of birth', '', 'col-6 col-md-4', 'date'],
            ['pan', 'PAN', 'maxlength=10 style=text-transform:uppercase', 'col-6 col-md-4'],
            ['uan', 'PF UAN', 'maxlength=12 inputmode=numeric', 'col-6 col-md-4'],
            ['esi_number', 'ESI IP number', 'maxlength=17 inputmode=numeric', 'col-6 col-md-4'],
            ['bank_name', 'Bank', 'maxlength=100', 'col-6 col-md-4'],
            ['bank_account', 'Account number', 'maxlength=20 inputmode=numeric', 'col-6 col-md-4'],
            ['ifsc', 'IFSC', 'maxlength=11 style=text-transform:uppercase', 'col-6 col-md-4'],
            ['phone', 'Phone', 'maxlength=20', 'col-6 col-md-4'],
            ['email', 'Email', 'maxlength=150', 'col-6 col-md-4', 'email'],
        ] as $f)
            <div class="{{ $f[3] }}">
                <label for="{{ $f[0] }}" class="form-label">{{ $f[1] }}</label>
                <input id="{{ $f[0] }}" name="{{ $f[0] }}" type="{{ $f[4] ?? 'text' }}" value="{{ $v($f[0]) }}" class="form-control @error($f[0]) is-invalid @enderror" {!! $f[2] !!}>
                @error($f[0])<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
        @endforeach
        <div class="col-6 col-md-4">
            <label for="gender" class="form-label">Gender</label>
            <select id="gender" name="gender" class="form-select">
                <option value="">—</option>
                @foreach (['male' => 'Male', 'female' => 'Female', 'other' => 'Other'] as $k => $label)<option value="{{ $k }}" @selected($v('gender') === $k)>{{ $label }}</option>@endforeach
            </select>
        </div>
        <div class="col-12">
            <label for="address" class="form-label">Address</label>
            <textarea id="address" name="address" rows="2" class="form-control" maxlength="1000">{{ $v('address') }}</textarea>
        </div>
        <div class="col-12 d-flex flex-wrap gap-3">
            @foreach (['pf_applicable' => 'PF applies', 'esi_applicable' => 'ESI applies', 'pt_applicable' => 'Professional tax applies', 'is_active' => 'Active'] as $flag => $label)
                <div class="form-check">
                    <input type="hidden" name="{{ $flag }}" value="0">
                    <input class="form-check-input" type="checkbox" name="{{ $flag }}" value="1" id="{{ $flag }}" @checked(old($flag, $employee->{$flag}))>
                    <label class="form-check-label" for="{{ $flag }}">{{ $label }}</label>
                </div>
            @endforeach
        </div>

        <div class="col-12">
            <h2 class="h6 fw-bold mt-2 mb-1">Salary structure <small class="text-secondary fw-normal">(monthly)</small></h2>
            @error('heads')<div class="text-danger small mb-2">{{ $message }}</div>@enderror
            <div class="table-responsive">
                <table class="table table-sm align-middle table-stack mb-0">
                    <thead class="table-light"><tr><th style="width: 3rem">Use</th><th>Pay head</th><th>Type</th><th>Calculation</th><th class="text-end" style="width: 12rem">Monthly amount</th></tr></thead>
                    <tbody>
                    @foreach ($heads as $h)
                        @php($on = old("heads.{$h->id}.on", $employee->exists ? $structure->has($h->id) : in_array($h->system_key, ['basic', 'hra', 'pf_employee', 'esi_employee', 'pt', 'pf_employer', 'esi_employer'], true)))
                        <tr>
                            <td data-label="Use">
                                <input type="hidden" name="heads[{{ $h->id }}][on]" value="0">
                                <input class="form-check-input" type="checkbox" name="heads[{{ $h->id }}][on]" value="1" @checked($on) aria-label="Use {{ $h->name }}">
                            </td>
                            <td data-label="Pay head" class="fw-semibold">{{ $h->name }}</td>
                            <td data-label="Type">{{ \App\Models\PayHead::TYPES[$h->type] }}</td>
                            <td data-label="Calculation" class="small">{{ \App\Models\PayHead::CALCULATIONS[$h->calculation] }}@if ($h->rate !== null) · {{ \App\Support\Decimal::trim($h->rate) }}%@endif</td>
                            <td data-label="Monthly amount">
                                @if (in_array($h->calculation, ['flat', 'overtime'], true))
                                    <input name="heads[{{ $h->id }}][amount]" value="{{ old("heads.{$h->id}.amount", $structure->get($h->id)?->amount) }}" class="form-control form-control-sm text-end @error("heads.{$h->id}.amount") is-invalid @enderror" inputmode="decimal" aria-label="{{ $h->name }} {{ $h->calculation === 'overtime' ? 'hourly rate' : 'amount' }}" @if ($h->calculation === 'overtime') placeholder="Rate per hour" @endif>
                                    @error("heads.{$h->id}.amount")<div class="invalid-feedback">{{ $message }}</div>@enderror
                                @else
                                    <span class="text-secondary small">Calculated</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            <p class="small text-secondary mt-2 mb-0">PF is worked out on basic up to ₹{{ number_format(config('erp.payroll.pf_wage_ceiling')) }}; ESI applies while monthly gross is up to ₹{{ number_format(config('erp.payroll.esi_wage_limit')) }}. Overtime takes a rate per hour; loan instalments are deducted automatically. <a href="{{ route('pay-heads.index') }}">Manage pay heads</a>.</p>
        </div>
    </div>

    <div class="d-flex flex-wrap gap-2 mt-4">
        <button class="btn btn-primary">{{ $employee->exists ? 'Save changes' : 'Add employee' }}</button>
        <a href="{{ $employee->exists ? route('employees.show', $employee) : route('employees.index') }}" class="btn btn-outline-secondary">Cancel</a>
    </div>
</form>
<datalist id="departments">@foreach (\App\Models\Employee::query()->whereNotNull('department')->distinct()->pluck('department') as $d)<option value="{{ $d }}">@endforeach</datalist>
@endsection
