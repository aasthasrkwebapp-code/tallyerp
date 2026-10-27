@extends('layouts.erp')

@section('title', 'Recurring Vouchers')

@section('content')
<div class="page-header">
    <div>
        <h1>Recurring Vouchers</h1>
        <p class="text-secondary mb-0">Vouchers that repeat on a schedule, such as rent, loan EMIs and monthly provisions. Due vouchers are made every morning, or when you press Run due now.</p>
    </div>
    @can('vouchers.create')
        <div class="d-flex flex-wrap gap-2">
            @if ($dueCount)
                <form method="POST" action="{{ route('recurring-vouchers.run-all') }}" data-confirm="Make the {{ $dueCount }} due voucher schedule(s) now?">@csrf<button class="btn btn-outline-primary"><i class="bi bi-play-circle me-1"></i>Run due now ({{ $dueCount }})</button></form>
            @endif
            <a href="{{ route('recurring-vouchers.create') }}" class="btn btn-primary" data-shortcut="c"><i class="bi bi-plus-lg me-1"></i>New recurring voucher</a>
        </div>
    @endcan
</div>

<div class="erp-card">
    <div class="table-responsive">
        <table class="table align-middle mb-0 table-stack">
            <thead class="table-light"><tr><th>Name</th><th>Type</th><th>Repeats</th><th>Next date</th><th>Mode</th><th class="text-end">Amount</th><th>Status</th></tr></thead>
            <tbody>
            @forelse ($items as $r)
                <tr>
                    <td data-label="Name"><a href="{{ route('recurring-vouchers.show', $r) }}" class="fw-semibold">{{ $r->name }}</a></td>
                    <td data-label="Type">{{ $r->type?->name }}</td>
                    <td data-label="Repeats">{{ $r->frequencyLabel() }}@if ($r->end_date)<div class="small text-secondary">until {{ $r->end_date->format('d-m-Y') }}</div>@endif</td>
                    <td data-label="Next date">{{ $r->next_date?->format('d-m-Y') ?? '—' }}</td>
                    <td data-label="Mode">{{ $r->mode === 'draft' ? 'Draft' : 'Post' }}</td>
                    <td data-label="Amount" class="amount">{{ \App\Support\Money::format($r->total) }}</td>
                    <td data-label="Status">
                        @if (! $r->is_active)<span class="badge text-bg-secondary">Paused</span>
                        @elseif (! $r->next_date)<span class="badge text-bg-light border">Ended</span>
                        @elseif ($r->next_date->toDateString() <= $today)<span class="badge text-bg-warning">Due</span>
                        @else<span class="badge text-bg-success">Scheduled</span>@endif
                        @if ($r->failed_count)<span class="badge text-bg-danger" title="Dates that could not be posted">{{ $r->failed_count }} failed</span>@endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="7" class="text-center text-secondary py-4">No recurring vouchers yet. Open any voucher and choose <strong>Make recurring</strong>, or create one here.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
