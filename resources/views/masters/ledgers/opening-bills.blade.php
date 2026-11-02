@extends('layouts.erp')

@section('title', 'Opening Bills')

@php($p = fn ($paise) => \App\Support\Money::format(abs($paise), paiseInput: true).($paise > 0 ? ' Dr' : ($paise < 0 ? ' Cr' : '')))
@php($canEdit = auth()->user()->can('masters.edit'))
@php($rows = old('bills', $bills->map(fn ($b) => ['id' => $b->id, 'bill_ref' => $b->bill_ref, 'date' => $b->date?->toDateString(), 'due_date' => $b->due_date?->toDateString(), 'amount' => $b->amount, 'side' => $b->side])->all()))
@php($rows = $canEdit ? array_merge(array_values($rows), array_fill(0, max(1, 3 - count($rows)), ['id' => null, 'bill_ref' => '', 'date' => '', 'due_date' => '', 'amount' => '', 'side' => $ledger->opening_balance_type])) : array_values($rows))

@section('content')
<div class="page-header">
    <div>
        <h1>Opening bills: {{ $ledger->name }}</h1>
        <p class="text-secondary mb-0">The bills that made up the opening balance when the books began{{ $begin ? ' on '.\Illuminate\Support\Carbon::parse($begin)->format('d M Y') : '' }}</p>
    </div>
    <div class="d-flex flex-wrap gap-2 no-print">
        @if ($canEdit)<a href="{{ route('ledgers.edit', $ledger) }}" class="btn btn-outline-secondary">Edit ledger</a>@endif
        <a href="{{ request()->fullUrlWithQuery(['export' => 'csv']) }}" class="btn btn-outline-secondary"><i class="bi bi-filetype-csv"></i> CSV</a>
    </div>
</div>

<div class="row g-3 mb-3">
    <div class="col-6 col-md-3"><div class="erp-card p-3"><div class="small text-secondary">Opening balance</div><div class="fw-semibold">{{ $opening === 0 ? '—' : $p($opening) }}</div></div></div>
    <div class="col-6 col-md-3"><div class="erp-card p-3"><div class="small text-secondary">In opening bills</div><div class="fw-semibold">{{ $net === 0 ? '—' : $p($net) }}</div></div></div>
    <div class="col-6 col-md-3"><div class="erp-card p-3"><div class="small text-secondary">On account</div><div class="fw-semibold">{{ $opening - $net === 0 ? '—' : $p($opening - $net) }}</div></div></div>
</div>

@unless ($ledger->maintain_bill_wise)
    <div class="alert alert-warning">This ledger is not kept bill-by-bill. Tick "Maintain balances bill-by-bill" on the ledger to enter opening bills.</div>
@endunless
@error('bills')<div class="alert alert-danger">{{ $message }}</div>@enderror

<form method="POST" action="{{ route('ledgers.opening-bills.update', $ledger) }}" class="erp-card">
    @csrf @method('PUT')
    <div class="table-responsive">
        <table class="table table-sm align-middle mb-0 table-stack" id="opening-bills">
            <thead class="table-light"><tr><th>Bill reference</th><th>Bill date</th><th>Due date</th><th class="text-end">Amount</th><th>Dr / Cr</th><th class="text-end">Settled</th></tr></thead>
            <tbody>
            @forelse ($rows as $i => $row)
                @php($done = $settled[mb_strtolower((string) $row['bill_ref'])] ?? 0)
                <tr>
                    <td data-label="Bill reference">
                        <input type="hidden" name="bills[{{ $i }}][id]" value="{{ $row['id'] ?? '' }}">
                        <input name="bills[{{ $i }}][bill_ref]" value="{{ $row['bill_ref'] }}" maxlength="100" class="form-control form-control-sm @error("bills.$i.bill_ref") is-invalid @enderror" aria-label="Bill reference" @disabled(! $canEdit)>
                        @error("bills.$i.bill_ref")<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </td>
                    <td data-label="Bill date">
                        <input type="date" name="bills[{{ $i }}][date]" value="{{ $row['date'] }}" max="{{ $begin ? \Illuminate\Support\Carbon::parse($begin)->toDateString() : '' }}" class="form-control form-control-sm @error("bills.$i.date") is-invalid @enderror" aria-label="Bill date" @disabled(! $canEdit)>
                        @error("bills.$i.date")<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </td>
                    <td data-label="Due date">
                        <input type="date" name="bills[{{ $i }}][due_date]" value="{{ $row['due_date'] }}" class="form-control form-control-sm @error("bills.$i.due_date") is-invalid @enderror" aria-label="Due date" @disabled(! $canEdit)>
                        @error("bills.$i.due_date")<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </td>
                    <td data-label="Amount">
                        <input name="bills[{{ $i }}][amount]" value="{{ $row['amount'] }}" inputmode="decimal" class="form-control form-control-sm text-end @error("bills.$i.amount") is-invalid @enderror" aria-label="Amount" @disabled(! $canEdit)>
                        @error("bills.$i.amount")<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </td>
                    <td data-label="Dr / Cr">
                        <select name="bills[{{ $i }}][side]" class="form-select form-select-sm" aria-label="Dr or Cr" @disabled(! $canEdit)>
                            <option value="Dr" @selected($row['side'] === 'Dr')>Dr</option>
                            <option value="Cr" @selected($row['side'] === 'Cr')>Cr</option>
                        </select>
                    </td>
                    <td data-label="Settled" class="amount">{{ $done ? \App\Support\Money::format($done, paiseInput: true) : '—' }}</td>
                </tr>
            @empty
                <tr><td colspan="6" class="text-center text-secondary py-4">No opening bills.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    @if ($canEdit && $ledger->maintain_bill_wise)
        <div class="p-3 d-flex flex-wrap gap-2">
            <button type="button" class="btn btn-outline-secondary" id="add-row"><i class="bi bi-plus-lg"></i> Add row</button>
            <button class="btn btn-primary" data-shortcut="s">Save opening bills</button>
        </div>
    @endif
</form>
<p class="small text-secondary mt-2">Dr is a bill the party owes you (for a customer, a sales invoice); Cr is one you owe or an advance received (for a supplier, a purchase bill). Together they may come to the opening balance or less; the rest stays on account. Settle opening bills with Agst Ref in receipts and payments. To remove a bill, clear its row. A bill already settled cannot be removed or cut below the settled amount.</p>
@endsection

@push('scripts')
<script>
(function () {
    var button = document.getElementById('add-row');
    if (!button) return;
    button.addEventListener('click', function () {
        var body = document.querySelector('#opening-bills tbody');
        var last = body.querySelector('tr:last-child');
        var row = last.cloneNode(true);
        var index = body.querySelectorAll('tr').length;
        row.querySelectorAll('input, select').forEach(function (el) {
            el.name = el.name.replace(/bills\[\d+\]/, 'bills[' + index + ']');
            el.classList.remove('is-invalid');
            if (el.tagName === 'INPUT') el.value = '';
        });
        row.querySelectorAll('.invalid-feedback').forEach(function (el) { el.remove(); });
        row.lastElementChild.textContent = '—';
        body.appendChild(row);
        row.querySelector('input:not([type=hidden])').focus();
    });
})();
</script>
@endpush
