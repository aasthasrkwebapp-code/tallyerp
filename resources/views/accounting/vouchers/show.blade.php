@extends('layouts.erp')

@section('title', $voucher->type->name.' '.$voucher->displayNumber())

@section('content')
@php($company = app(\App\Support\CurrentCompany::class)->get())
<div class="page-header no-print">
    <div>
        <h1>{{ $voucher->type->name }} {{ $voucher->displayNumber() }} @include('accounting.vouchers._status', ['status' => $voucher->status])</h1>
        <p class="text-secondary mb-0">{{ $voucher->date->format('d M Y') }} · Revision {{ $voucher->revision }} · Entered by {{ $voucher->creator?->name ?? '—' }}</p>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <button type="button" class="btn btn-outline-secondary" onclick="window.print()"><i class="bi bi-printer me-1"></i>Print</button>
        @if (! $voucher->isCancelled() && ! $voucher->source_type)
            @if (($voucher->isPosted() && auth()->user()->can('vouchers.edit')) || (! $voucher->isPosted() && (auth()->user()->can('vouchers.edit') || $voucher->created_by === auth()->id())))
                <a href="{{ route('vouchers.edit', $voucher) }}" class="btn btn-outline-primary"><i class="bi bi-pencil me-1"></i>Alter</a>
            @endif
        @endif
        @if ($voucher->isDraft() && auth()->user()->can('vouchers.post'))
            <form method="POST" action="{{ route('vouchers.post', $voucher) }}">@csrf<button class="btn btn-primary">Post</button></form>
        @endif
        @if ($voucher->isPending() && auth()->user()->can('vouchers.approve'))
            <form method="POST" action="{{ route('vouchers.post', $voucher) }}">@csrf<button class="btn btn-success"><i class="bi bi-check2-circle me-1"></i>Approve &amp; post</button></form>
        @endif
        @if (($voucher->isDraft() || $voucher->isPending()) && auth()->user()->can('vouchers.delete'))
            <form method="POST" action="{{ route('vouchers.destroy', $voucher) }}" data-confirm="Delete this draft?">@csrf @method('DELETE')<button class="btn btn-outline-danger">Delete draft</button></form>
        @endif
        @if ($voucher->isPosted() && auth()->user()->can('vouchers.cancel'))
            <button class="btn btn-outline-danger" data-bs-toggle="collapse" data-bs-target="#cancel-panel" aria-expanded="false">Cancel / reverse</button>
        @endif
    </div>
</div>

@if ($voucher->source_type === \App\Models\Invoice::class && $voucher->source)
    <div class="alert alert-info small no-print"><i class="bi bi-receipt me-1"></i>Generated from
        <a href="{{ route('invoices.show', $voucher->source) }}">{{ $voucher->source->config()['label'] }} {{ $voucher->source->displayNumber() }}</a>. Alter or delete it from there.</div>
@elseif ($voucher->source_type === \App\Models\PayrollRun::class && $voucher->source)
    <div class="alert alert-info small no-print"><i class="bi bi-wallet2 me-1"></i>Generated from
        <a href="{{ route('payroll-runs.show', $voucher->source) }}">payroll for {{ $voucher->source->label() }}</a>. Cancel it from there.</div>
@elseif ($voucher->source_type === \App\Models\TransportExpense::class && $voucher->source)
    <div class="alert alert-info small no-print"><i class="bi bi-truck me-1"></i>Generated from a transport expense ({{ $voucher->source->categoryLabel() }}, {{ $voucher->source->vehicle->number }})@if ($voucher->source->trip) on <a href="{{ route('trips.show', $voucher->source->trip) }}">trip {{ $voucher->source->trip->number }}</a>@endif. Cancel it from <a href="{{ route('transport-expenses.index') }}">transport expenses</a>.</div>
@endif

@if ($voucher->isPosted() && auth()->user()->can('vouchers.cancel'))
    <div class="collapse no-print" id="cancel-panel">
        <div class="row g-3 mb-3">
            <div class="col-12 col-lg-6">
                <form method="POST" action="{{ route('vouchers.cancel', $voucher) }}" class="erp-card p-3 h-100" data-confirm="Cancel voucher {{ $voucher->voucher_number }}? It stays in the books as cancelled.">
                    @csrf
                    <h2 class="h6 fw-bold">Cancel voucher</h2>
                    <p class="small text-secondary">The voucher keeps its number but no longer affects any balance.</p>
                    <label for="reason" class="form-label">Reason</label>
                    <input id="reason" name="reason" class="form-control mb-2" required maxlength="255">
                    <button class="btn btn-danger">Cancel voucher</button>
                </form>
            </div>
            @unless ($voucher->reversal)
                <div class="col-12 col-lg-6">
                    <form method="POST" action="{{ route('vouchers.reverse', $voucher) }}" class="erp-card p-3 h-100">
                        @csrf
                        <h2 class="h6 fw-bold">Post a reversing entry</h2>
                        <p class="small text-secondary">Creates a journal with every line on the opposite side, dated below.</p>
                        <label for="rev-date" class="form-label">Reversal date</label>
                        <input id="rev-date" type="date" name="date" class="form-control mb-2" value="{{ now()->toDateString() }}" required>
                        <input name="narration" class="form-control mb-2" placeholder="Narration (optional)" maxlength="500" aria-label="Narration">
                        <button class="btn btn-outline-danger">Post reversal</button>
                    </form>
                </div>
            @endunless
        </div>
    </div>
@endif

<div class="erp-card p-3 p-md-4 mb-3">
    <div class="d-none d-print-block mb-3">
        <div class="fs-5 fw-bold">{{ $company->displayName() }}</div>
        <div class="small">{{ $company->address }} {{ $company->city }} {{ $company->state }} {{ $company->gstin ? '· GSTIN '.$company->gstin : '' }}</div>
        <div class="fw-semibold mt-2">{{ $voucher->type->name }} Voucher</div>
    </div>

    @if ($voucher->isCancelled())
        <div class="alert alert-danger py-2">Cancelled on {{ $voucher->cancelled_at?->format('d M Y H:i') }}: {{ $voucher->cancellation_reason }}</div>
    @endif
    @if ($voucher->reversalOf)
        <div class="alert alert-info py-2">Reverses <a href="{{ route('vouchers.show', $voucher->reversalOf) }}">{{ $voucher->reversalOf->voucher_number }}</a>.</div>
    @endif
    @if ($voucher->reversal)
        <div class="alert alert-info py-2">Reversed by <a href="{{ route('vouchers.show', $voucher->reversal) }}">{{ $voucher->reversal->voucher_number }}</a>.</div>
    @endif

    <dl class="row small mb-3">
        <dt class="col-5 col-md-2">Number</dt><dd class="col-7 col-md-4">{{ $voucher->displayNumber() }}</dd>
        <dt class="col-5 col-md-2">Date</dt><dd class="col-7 col-md-4">{{ $voucher->date->format('d M Y') }}</dd>
        <dt class="col-5 col-md-2">Reference</dt><dd class="col-7 col-md-4">{{ $voucher->reference ?: '—' }} {{ $voucher->reference_date ? '('.$voucher->reference_date->format('d M Y').')' : '' }}</dd>
        @if ($voucher->approver)
            <dt class="col-5 col-md-2">Approved by</dt><dd class="col-7 col-md-4">{{ $voucher->approver->name }} · {{ $voucher->approved_at?->format('d M Y H:i') }}</dd>
        @endif
    </dl>

    <div class="table-responsive">
        <table class="table table-sm align-middle mb-2">
            <thead class="table-light"><tr><th>Particulars</th><th class="text-end">Debit</th><th class="text-end">Credit</th></tr></thead>
            <tbody>
            @foreach ($voucher->entries as $entry)
                <tr>
                    <td>
                        <a href="{{ Route::has('reports.ledger') ? route('reports.ledger', ['ledger' => $entry->account_id]) : '#' }}" class="fw-semibold text-body">{{ $entry->account->name }}</a>
                        @if ($entry->costCentre)<span class="badge text-bg-light border ms-1">{{ $entry->costCentre->name }}</span>@endif
                        @if ($entry->narration)<div class="small text-secondary">{{ $entry->narration }}</div>@endif
                        @foreach ($entry->billAllocations as $bill)
                            <div class="small text-secondary">↳ {{ \App\Models\BillAllocation::TYPES[$bill->bill_type] }} {{ $bill->bill_ref }} · {{ \App\Support\Money::format($bill->amount) }} {{ $bill->side }}{{ $bill->due_date ? ' · due '.$bill->due_date->format('d M Y') : '' }}</div>
                        @endforeach
                    </td>
                    <td class="amount">{{ (float) $entry->debit ? \App\Support\Money::format($entry->debit) : '' }}</td>
                    <td class="amount">{{ (float) $entry->credit ? \App\Support\Money::format($entry->credit) : '' }}</td>
                </tr>
            @endforeach
            </tbody>
            <tfoot>
                @php($dr = $voucher->entries->sum(fn ($e) => \App\Support\Money::toPaise($e->debit)))
                @php($cr = $voucher->entries->sum(fn ($e) => \App\Support\Money::toPaise($e->credit)))
                <tr class="row-total"><td>Total</td><td class="amount">{{ \App\Support\Money::format($dr, paiseInput: true) }}</td><td class="amount">{{ \App\Support\Money::format($cr, paiseInput: true) }}</td></tr>
            </tfoot>
        </table>
    </div>
    @if ($voucher->narration)
        <div class="small"><strong>Narration:</strong> {{ $voucher->narration }}</div>
    @endif
</div>

@if ($voucher->revisions->isNotEmpty())
    <div class="erp-card no-print">
        <div class="erp-card-header">Alteration history</div>
        <ul class="list-group list-group-flush">
            @foreach ($voucher->revisions as $rev)
                <li class="list-group-item small">
                    <div class="d-flex flex-wrap justify-content-between gap-2">
                        <span><strong>Revision {{ $rev->revision }}</strong> by {{ $rev->user?->name ?? '—' }} on {{ $rev->created_at->format('d M Y H:i') }}</span>
                        <span>{{ $rev->reason ?: 'No reason given' }}</span>
                    </div>
                    <div class="text-secondary">Before this change: date {{ $rev->snapshot['date'] }}, total {{ \App\Support\Money::format($rev->snapshot['total']) }},
                        @foreach ($rev->snapshot['entries'] as $e)
                            {{ \App\Models\Account::withoutGlobalScopes()->find($e['account_id'])?->name }} {{ $e['side'] }} {{ \App\Support\Money::format($e['amount']) }}@if (! $loop->last); @endif
                        @endforeach
                    </div>
                </li>
            @endforeach
        </ul>
    </div>
@endif
@endsection
