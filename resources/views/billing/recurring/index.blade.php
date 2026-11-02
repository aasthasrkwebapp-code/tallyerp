@extends('layouts.erp')

@section('title', 'Recurring Invoices')

@section('content')
<div class="page-header">
    <div>
        <h1>Recurring invoices</h1>
        <p class="text-secondary mb-0">Sales and purchase invoices that repeat on a schedule, such as rent, maintenance contracts and subscriptions. Due invoices are made every morning, or when you press Run due now.</p>
    </div>
    @if ($dueCount)
        <form method="POST" action="{{ route('recurring-invoices.run-all') }}" data-confirm="Make the invoices of {{ $dueCount }} due schedule(s) now?">@csrf<button class="btn btn-outline-primary"><i class="bi bi-play-circle me-1"></i>Run due now ({{ $dueCount }})</button></form>
    @endif
</div>

<div class="erp-card">
    <div class="table-responsive">
        <table class="table align-middle mb-0 table-stack">
            <thead class="table-light"><tr><th>Name</th><th>Type</th><th>Party</th><th>Repeats</th><th>Next date</th><th>Mode</th><th class="text-end">Amount</th><th>Status</th></tr></thead>
            <tbody>
            @forelse ($items as $r)
                <tr>
                    <td data-label="Name"><a href="{{ route('recurring-invoices.show', $r) }}" class="fw-semibold">{{ $r->name }}</a></td>
                    <td data-label="Type">{{ $r->config()['label'] }}</td>
                    <td data-label="Party">{{ $r->party?->name }}</td>
                    <td data-label="Repeats">{{ $r->frequencyLabel() }}@if ($r->end_date)<div class="small text-secondary">until {{ $r->end_date->format('d-m-Y') }}</div>@endif</td>
                    <td data-label="Next date">{{ $r->next_date?->format('d-m-Y') ?? '—' }}</td>
                    <td data-label="Mode">{{ $r->mode === 'draft' ? 'Draft' : 'Post' }}</td>
                    <td data-label="Amount" class="amount">{{ \App\Support\Money::format($r->total) }}</td>
                    <td data-label="Status">
                        @if (! $r->is_active)<span class="badge text-bg-secondary">Paused</span>
                        @elseif (! $r->next_date)<span class="badge text-bg-light border">Ended</span>
                        @elseif ($r->next_date->toDateString() <= $today)<span class="badge text-bg-warning">Due</span>
                        @else<span class="badge text-bg-success">Scheduled</span>@endif
                        @if ($r->failed_count)<span class="badge text-bg-danger" title="Dates that could not be saved">{{ $r->failed_count }} failed</span>@endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="8" class="text-center text-secondary py-4">No recurring invoices yet. Open a sales or purchase invoice and choose <strong>Make recurring</strong>.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
