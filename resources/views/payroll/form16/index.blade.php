@extends('layouts.erp')

@section('title', 'Form 16')

@section('content')
@php($m = fn (int $p) => \App\Support\Money::format($p, paiseInput: true))
<div class="page-header">
    <div>
        <h1>Form 16</h1>
        <p class="text-secondary mb-0">The yearly certificate of tax deducted from salary, worked out from posted payrolls and each employee's tax declaration.</p>
    </div>
    <div class="d-flex flex-wrap gap-2 no-print">
        <form method="GET" class="d-flex gap-2">
            <label for="year" class="visually-hidden">Financial year</label>
            <select id="year" name="year" class="form-select" onchange="this.form.submit()">
                @foreach ($years as $y)
                    <option value="{{ $y->id }}" @selected($year?->id === $y->id)>FY {{ $y->name }}</option>
                @endforeach
            </select>
        </form>
        @if ($year)<a href="{{ request()->fullUrlWithQuery(['export' => 'csv', 'year' => $year->id]) }}" class="btn btn-outline-secondary"><i class="bi bi-filetype-csv"></i> CSV</a>@endif
    </div>
</div>

@if (! $company->tan)
    <div class="alert alert-warning">The company has no TAN. Form 16 needs the deductor's TAN; add it under <a href="{{ route('companies.edit', $company) }}">company details</a>.</div>
@endif

<div class="erp-card mb-3">
    <div class="table-responsive">
        <table class="table table-sm align-middle mb-0 table-stack">
            <thead class="table-light"><tr><th>Employee</th><th>PAN</th><th>Regime</th><th class="text-end">Gross salary</th><th class="text-end">Taxable income</th><th class="text-end">Tax payable</th><th class="text-end">TDS deducted</th><th class="text-end">Balance</th><th class="text-end no-print"></th></tr></thead>
            <tbody>
            @forelse ($rows as $r)
                <tr>
                    <td data-label="Employee"><a href="{{ route('form16.show', [$r->employee, 'year' => $year->id]) }}">{{ $r->employee->name }}</a> <span class="small text-secondary">{{ $r->employee->code }}</span></td>
                    <td data-label="PAN">{!! $r->employee->pan ? e($r->employee->pan) : '<span class="badge text-bg-warning">No PAN</span>' !!}</td>
                    <td data-label="Regime">{{ ucfirst($r->regime) }}</td>
                    <td data-label="Gross salary" class="amount">{{ $m($r->gross) }}</td>
                    <td data-label="Taxable income" class="amount">{{ $m($r->taxable) }}</td>
                    <td data-label="Tax payable" class="amount">{{ $m($r->tax['total']) }}</td>
                    <td data-label="TDS deducted" class="amount">{{ $m($r->tds) }}</td>
                    <td data-label="Balance" class="amount {{ $r->balance > 0 ? 'text-danger' : '' }}">{{ $r->balance === 0 ? '—' : ($r->balance > 0 ? $m($r->balance).' short' : $m(-$r->balance).' excess') }}</td>
                    <td data-label="" class="text-end no-print text-nowrap">
                        @can('payroll.edit')<a href="{{ route('form16.declaration', [$r->employee, 'year' => $year->id]) }}" class="btn btn-sm btn-outline-secondary">Declaration</a>@endcan
                        <a href="{{ route('form16.show', [$r->employee, 'year' => $year->id, 'export' => 'pdf']) }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-file-earmark-pdf"></i> PDF</a>
                    </td>
                </tr>
            @empty
                <tr><td colspan="9" class="text-center text-secondary py-4">No posted payroll in this year.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>

@if ($year)
<div class="erp-card p-3">
    <h2 class="h6">Quarterly TDS returns (Form 24Q)</h2>
    <p class="small text-secondary">The acknowledgement number of each quarter's return is printed in Part A.</p>
    @can('payroll.edit')
        <form method="POST" action="{{ route('form16.returns', ['year' => $year->id]) }}">
            @csrf @method('PUT')
            <div class="row g-2">
                @foreach ($quarters as $q => $label)
                    <div class="col-12 col-md-6 col-xl-3">
                        <label class="form-label small mb-1" for="receipt-{{ $q }}">{{ $label }}</label>
                        <div class="input-group input-group-sm">
                            <input id="receipt-{{ $q }}" name="receipt[{{ $q }}]" class="form-control @error("receipt.$q") is-invalid @enderror" maxlength="20" placeholder="Receipt number" value="{{ old("receipt.$q", $returns[$q]->receipt_number ?? '') }}">
                            <input name="filed_on[{{ $q }}]" type="date" class="form-control" aria-label="Filed on" value="{{ old("filed_on.$q", isset($returns[$q]) ? $returns[$q]->filed_on?->toDateString() : '') }}">
                        </div>
                        @error("receipt.$q")<div class="small text-danger">{{ $message }}</div>@enderror
                    </div>
                @endforeach
            </div>
            <button class="btn btn-sm btn-primary mt-2">Save receipt numbers</button>
        </form>
    @else
        <ul class="small mb-0">
            @foreach ($quarters as $q => $label)<li>{{ $label }}: {{ $returns[$q]->receipt_number ?? 'not entered' }}</li>@endforeach
        </ul>
    @endcan
</div>
@endif
@endsection
