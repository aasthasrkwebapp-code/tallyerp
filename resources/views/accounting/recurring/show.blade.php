@extends('layouts.erp')

@section('title', 'Recurring: '.$recurring->name)

@section('content')
@php($user = auth()->user())
@php($today = now()->toDateString())
<div class="page-header">
    <div>
        <h1>{{ $recurring->name }}
            @if (! $recurring->is_active)<span class="badge text-bg-secondary">Paused</span>
            @elseif (! $recurring->next_date)<span class="badge text-bg-light border">Ended</span>
            @elseif ($recurring->isDue($today))<span class="badge text-bg-warning">Due</span>
            @else<span class="badge text-bg-success">Scheduled</span>@endif
        </h1>
        <p class="text-secondary mb-0">{{ $recurring->type?->name }} · {{ $recurring->frequencyLabel() }} from {{ $recurring->start_date->format('d M Y') }}@if ($recurring->end_date) to {{ $recurring->end_date->format('d M Y') }}@endif · Set up by {{ $recurring->creator?->name ?? '—' }}</p>
    </div>
    <div class="d-flex flex-wrap gap-2">
        @if ($recurring->isDue($today) && $user->can('vouchers.create'))
            <form method="POST" action="{{ route('recurring-vouchers.run', $recurring) }}">@csrf<button class="btn btn-primary"><i class="bi bi-play-circle me-1"></i>Make due vouchers now</button></form>
        @endif
        @can('vouchers.create')
            <a href="{{ route('recurring-vouchers.edit', $recurring) }}" class="btn btn-outline-primary"><i class="bi bi-pencil me-1"></i>Alter</a>
        @endcan
        @can('vouchers.delete')
            <form method="POST" action="{{ route('recurring-vouchers.destroy', $recurring) }}" data-confirm="Delete the recurring voucher {{ $recurring->name }}? Vouchers already made are kept.">@csrf @method('DELETE')<button class="btn btn-outline-danger">Delete</button></form>
        @endcan
    </div>
</div>

@php($lastFailed = $runs->first(fn ($r) => $r->status === 'failed' && $recurring->next_date && $r->date->toDateString() === $recurring->next_date->toDateString()))
@if ($lastFailed)
    <div class="alert alert-danger small">The voucher for {{ $lastFailed->date->format('d M Y') }} could not be made: {{ $lastFailed->message }} It will be tried again on the next run. Fix the cause, or alter the next date.</div>
@endif

<div class="row g-3 mb-3">
    <div class="col-12 col-lg-8">
        <div class="erp-card h-100">
            <div class="table-responsive">
                <table class="table align-middle mb-0 table-stack">
                    <thead class="table-light"><tr><th>Ledger</th><th class="text-end">Debit</th><th class="text-end">Credit</th><th>Bill / cost centre</th></tr></thead>
                    <tbody>
                    @foreach ($recurring->lines as $l)
                        <tr>
                            <td data-label="Ledger">{{ $l->account?->name }}@if ($l->narration)<div class="small text-secondary">{{ $l->narration }}</div>@endif</td>
                            <td data-label="Debit" class="amount">{{ $l->side === 'Dr' ? \App\Support\Money::format($l->amount) : '' }}</td>
                            <td data-label="Credit" class="amount">{{ $l->side === 'Cr' ? \App\Support\Money::format($l->amount) : '' }}</td>
                            <td data-label="Bill / cost centre" class="small">
                                @if ($l->bill_type === 'new_ref')New bill (voucher no.)@if ($l->due_days !== null), due in {{ $l->due_days }} days @endif
                                @elseif ($l->bill_type === 'on_account')On account @endif
                                @if ($l->costCentre)<div>{{ $l->costCentre->name }}</div>@endif
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            @if ($recurring->narration)<div class="p-3 border-top small"><span class="text-secondary">Narration:</span> {{ $recurring->narration }}</div>@endif
        </div>
    </div>
    <div class="col-12 col-lg-4">
        <div class="erp-card p-3 h-100 small">
            <div class="mb-2"><span class="text-secondary">Each voucher is</span><div>{{ \App\Models\RecurringVoucher::MODES[$recurring->mode] }}</div></div>
            <div class="mb-2"><span class="text-secondary">Amount</span><div class="fw-semibold">₹{{ \App\Support\Money::format($recurring->total) }}</div></div>
            @if ($recurring->reference)<div class="mb-2"><span class="text-secondary">Reference</span><div>{{ $recurring->reference }}</div></div>@endif
            <div class="text-secondary">Next dates</div>
            @forelse ($upcoming as $d)
                <div>{{ \Illuminate\Support\Carbon::parse($d)->format('d M Y') }}@if ($d <= $today) <span class="badge text-bg-warning">due</span>@endif</div>
            @empty
                <div>None. The schedule is paused or has ended.</div>
            @endforelse
        </div>
    </div>
</div>

<div class="erp-card">
    <div class="erp-card-header">Run log</div>
    <div class="table-responsive">
        <table class="table table-sm align-middle mb-0 table-stack small">
            <thead class="table-light"><tr><th>For date</th><th>Result</th><th>Voucher</th><th>Run</th></tr></thead>
            <tbody>
            @forelse ($runs as $run)
                <tr>
                    <td data-label="For date">{{ $run->date->format('d-m-Y') }}</td>
                    <td data-label="Result">@if ($run->status === 'created')<span class="badge text-bg-success">Made</span>@else<span class="badge text-bg-danger">Failed</span> {{ $run->message }}@endif</td>
                    <td data-label="Voucher">@if ($run->voucher)<a href="{{ route('vouchers.show', $run->voucher) }}">{{ $run->voucher->displayNumber() }}</a> @include('accounting.vouchers._status', ['status' => $run->voucher->status])@elseif ($run->status === 'created')<span class="text-secondary">deleted</span>@else — @endif</td>
                    <td data-label="Run">{{ $run->created_at?->format('d-m-Y H:i') }} · {{ $run->runner?->name ?? 'scheduler' }}</td>
                </tr>
            @empty
                <tr><td colspan="4" class="text-center text-secondary py-3">No vouchers made yet.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
