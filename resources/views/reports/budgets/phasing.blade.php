@extends('layouts.erp')

@section('title', 'Monthly Phasing')

@section('content')
<div class="page-header">
    <div>
        <h1>Monthly phasing</h1>
        <p class="text-secondary mb-0">{{ $budget->name }} · {{ $budget->from_date->format('d M Y') }} to {{ $budget->to_date->format('d M Y') }} · {{ count($periods) }} months</p>
    </div>
    <a href="{{ route('budgets.show', $budget) }}" class="btn btn-outline-secondary">Back to budget</a>
</div>

<p class="small text-secondary">Lines budgeted on transactions can be spread evenly by days (the default) or given an amount for each month, for seasonal sales or one-off costs. Budget to date then adds the months before the report date and that month's share by days. Saving sets each phased line's budget to the total of its months. Closing-balance lines are not phased.</p>

@if ($errors->any())<div class="alert alert-danger small">{{ $errors->first() }}</div>@endif

@if ($lines->isEmpty())
    <div class="erp-card p-4 text-secondary">This budget has no lines on transactions to phase.</div>
@else
<form method="POST" action="{{ route('budgets.phasing.update', $budget) }}">
    @csrf @method('PUT')
    @foreach ($lines as $line)
        @php($monthly = $line->monthly($budget))
        @php($mode = old("mode.{$line->id}", $line->isPhased() ? 'monthly' : 'even'))
        <div class="erp-card mb-3 phasing-line" data-line="{{ $line->id }}">
            <div class="erp-card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
                <span>{{ $line->account?->name ?? $line->group?->name }}@if ($line->costCentre) <span class="small text-secondary"><i class="bi bi-bullseye"></i> {{ $line->costCentre->name }}</span>@endif</span>
                <span class="d-flex flex-wrap gap-3 small">
                    <span class="form-check mb-0"><input class="form-check-input phasing-mode" type="radio" name="mode[{{ $line->id }}]" value="even" id="even-{{ $line->id }}" @checked($mode === 'even')><label class="form-check-label" for="even-{{ $line->id }}">Even by days</label></span>
                    <span class="form-check mb-0"><input class="form-check-input phasing-mode" type="radio" name="mode[{{ $line->id }}]" value="monthly" id="monthly-{{ $line->id }}" @checked($mode === 'monthly')><label class="form-check-label" for="monthly-{{ $line->id }}">By month</label></span>
                </span>
            </div>
            <div class="p-3">
                <div class="row g-2">
                    @foreach ($periods as $i => [$from, $to, $label])
                        <div class="col-6 col-sm-4 col-md-3 col-xl-2">
                            <label class="form-label small mb-0" for="m-{{ $line->id }}-{{ $i }}">{{ $label }}</label>
                            <input id="m-{{ $line->id }}-{{ $i }}" name="months[{{ $line->id }}][{{ $i }}]" value="{{ old("months.{$line->id}.{$i}", \App\Support\Money::fromPaise($monthly[$i])) }}"
                                   class="form-control form-control-sm text-end phasing-month @error("months.{$line->id}.{$i}") is-invalid @enderror" inputmode="decimal">
                        </div>
                    @endforeach
                </div>
                <div class="d-flex justify-content-end gap-3 small mt-2">
                    <span class="text-secondary">Budget now {{ \App\Support\Money::format($line->amount) }}</span>
                    <span>Months total <strong class="phasing-total" data-budget="{{ \App\Support\Money::toPaise((string) $line->amount) }}">0.00</strong></span>
                </div>
            </div>
        </div>
    @endforeach
    <div class="d-flex gap-2 mb-5">
        <button class="btn btn-primary" data-shortcut="s">Save phasing</button>
        <a href="{{ route('budgets.show', $budget) }}" class="btn btn-outline-secondary">Cancel</a>
    </div>
</form>
@endif
@endsection

@push('scripts')
<script>
document.querySelectorAll('.phasing-line').forEach(function (card) {
    var inputs = card.querySelectorAll('.phasing-month');
    var total = card.querySelector('.phasing-total');
    function sum() {
        var t = 0;
        inputs.forEach(function (i) { t += Math.round(parseFloat((i.value || '0').replace(/,/g, '')) * 100) || 0; });
        total.textContent = (t / 100).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        var differs = card.querySelector('.phasing-mode[value="monthly"]').checked && t !== parseInt(total.dataset.budget, 10);
        total.classList.toggle('text-danger', differs);
        total.title = differs ? 'Saving changes the budget to this total' : '';
    }
    function mode() {
        var even = card.querySelector('.phasing-mode[value="even"]').checked;
        inputs.forEach(function (i) { i.readOnly = even; i.classList.toggle('bg-light', even); });
        sum();
    }
    inputs.forEach(function (i) { i.addEventListener('input', sum); });
    card.querySelectorAll('.phasing-mode').forEach(function (r) { r.addEventListener('change', mode); });
    sum(); mode();
});
</script>
@endpush
