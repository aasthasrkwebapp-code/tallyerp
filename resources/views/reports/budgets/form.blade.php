@extends('layouts.erp')

@section('title', ($budget->exists ? 'Alter ' : 'New ').'budget')

@section('content')
<div class="page-header">
    <div>
        <h1>{{ $budget->exists ? 'Alter budget' : 'New budget' }}</h1>
        <p class="text-secondary mb-0">Enter each amount on its natural side: expenses and assets as debits, income and liabilities as credits, all as positive numbers.</p>
    </div>
    <a href="{{ $budget->exists ? route('budgets.show', $budget) : route('budgets.index') }}" class="btn btn-outline-secondary">Back</a>
</div>

@if ($errors->any())
    <div class="alert alert-danger small"><ul class="mb-0">@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>
@endif

<form method="POST" action="{{ $budget->exists ? route('budgets.update', $budget) : route('budgets.store') }}" novalidate>
    @csrf
    @if ($budget->exists) @method('PUT') @endif

    <div class="erp-card p-3 mb-3">
        <div class="row g-3">
            <div class="col-12 col-md-6 col-lg-4">
                <label for="name" class="form-label">Name</label>
                <input id="name" name="name" value="{{ $input['name'] }}" class="form-control @error('name') is-invalid @enderror" maxlength="100" required placeholder="E.g. Budget 2026-27">
            </div>
            <div class="col-6 col-md-3 col-lg-2">
                <label for="from_date" class="form-label">From</label>
                <input id="from_date" type="date" name="from_date" value="{{ $input['from_date'] }}" class="form-control @error('from_date') is-invalid @enderror" required>
            </div>
            <div class="col-6 col-md-3 col-lg-2">
                <label for="to_date" class="form-label">To</label>
                <input id="to_date" type="date" name="to_date" value="{{ $input['to_date'] }}" class="form-control @error('to_date') is-invalid @enderror" required>
            </div>
            <div class="col-12 col-lg-4">
                <label for="description" class="form-label">Description <span class="text-secondary small">(optional)</span></label>
                <input id="description" name="description" value="{{ $input['description'] }}" class="form-control" maxlength="2000">
            </div>
        </div>
    </div>

    <div class="erp-card mb-3">
        <div class="erp-card-header d-flex justify-content-between align-items-center">
            <span>Lines</span>
            <button type="button" class="btn btn-sm btn-outline-primary" id="add-bline" data-shortcut="a"><i class="bi bi-plus-lg"></i> Add line</button>
        </div>
        <div id="blines" class="p-2 p-md-3">
            @foreach ($input['lines'] as $i => $l)
                <div class="row g-2 mb-2 pb-2 border-bottom bline">
                    <div class="col-12 col-md-5">
                        <label class="form-label small d-md-none">Group or ledger</label>
                        <select name="lines[{{ $i }}][target]" class="form-select @error("lines.$i.target") is-invalid @enderror" aria-label="Group or ledger">
                            <option value="">Select group or ledger…</option>
                            <optgroup label="Groups">
                                @foreach ($groups as $g)<option value="g:{{ $g->id }}" @selected(($l['target'] ?? '') === 'g:'.$g->id)>{{ str_repeat('· ', $g->depth) }}{{ $g->name }}</option>@endforeach
                            </optgroup>
                            <optgroup label="Ledgers">
                                @foreach ($ledgers as $a)<option value="l:{{ $a->id }}" @selected(($l['target'] ?? '') === 'l:'.$a->id)>{{ $a->name }} ({{ $a->group?->name }})</option>@endforeach
                            </optgroup>
                        </select>
                    </div>
                    <div class="col-6 col-md-2">
                        <label class="form-label small d-md-none">Cost centre</label>
                        <select name="lines[{{ $i }}][cost_centre_id]" class="form-select @error("lines.$i.cost_centre_id") is-invalid @enderror" aria-label="Cost centre">
                            <option value="">All cost centres</option>
                            @foreach ($costCentres as $c)<option value="{{ $c->id }}" @selected((int) ($l['cost_centre_id'] ?? 0) === $c->id)>{{ $c->name }}</option>@endforeach
                        </select>
                    </div>
                    <div class="col-6 col-md-3">
                        <label class="form-label small d-md-none">Basis</label>
                        <select name="lines[{{ $i }}][basis]" class="form-select @error("lines.$i.basis") is-invalid @enderror" aria-label="Basis">
                            @foreach (\App\Models\BudgetLine::BASES as $k => $label)<option value="{{ $k }}" @selected(($l['basis'] ?? 'nett') === $k)>{{ $label }}</option>@endforeach
                        </select>
                    </div>
                    <div class="col-10 col-md-2 d-flex gap-1">
                        <div class="flex-grow-1">
                            <label class="form-label small d-md-none">Amount</label>
                            <input name="lines[{{ $i }}][amount]" value="{{ $l['amount'] ?? '' }}" inputmode="decimal" class="form-control text-end @error("lines.$i.amount") is-invalid @enderror" aria-label="Amount" autocomplete="off">
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
        <div class="border-top px-3 py-2 small text-secondary">"Transactions in the period" compares with what was booked during the budget (shared out by days up to the report date). "Closing balance" compares with the balance on the report date, for example a bank balance or debtors limit. To remove a line, clear its group and amount.</div>
    </div>

    <button class="btn btn-primary mb-5" data-shortcut="s">Save budget</button>
</form>

@push('scripts')
<script>
(function () {
    var box = document.getElementById('blines');
    document.getElementById('add-bline').addEventListener('click', function () {
        var rows = box.querySelectorAll('.bline');
        var copy = rows[rows.length - 1].cloneNode(true);
        var n = rows.length;
        copy.querySelectorAll('[name]').forEach(function (el) {
            el.name = el.name.replace(/lines\[\d+\]/, 'lines[' + n + ']');
            if (el.tagName === 'SELECT') el.selectedIndex = 0; else el.value = '';
            el.classList.remove('is-invalid');
        });
        box.appendChild(copy);
        copy.querySelector('[name$="[target]"]').focus();
    });
})();
</script>
@endpush
@endsection
