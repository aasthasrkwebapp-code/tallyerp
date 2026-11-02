@extends('layouts.erp')

@section('title', 'Recurring: '.$recurring->name)

@php($user = auth()->user())
@php($today = now()->toDateString())
@php($module = $recurring->config()['module'])

@section('content')
<div class="page-header">
    <div>
        <h1>{{ $recurring->name }}
            @if (! $recurring->is_active)<span class="badge text-bg-secondary">Paused</span>
            @elseif (! $recurring->next_date)<span class="badge text-bg-light border">Ended</span>
            @elseif ($recurring->isDue($today))<span class="badge text-bg-warning">Due</span>
            @else<span class="badge text-bg-success">Scheduled</span>@endif
        </h1>
        <p class="text-secondary mb-0">{{ $recurring->config()['label'] }} · {{ $recurring->party?->name }} · {{ $recurring->frequencyLabel() }} from {{ $recurring->start_date->format('d M Y') }}@if ($recurring->end_date) to {{ $recurring->end_date->format('d M Y') }}@endif · Set up by {{ $recurring->creator?->name ?? '—' }}</p>
    </div>
    <div class="d-flex flex-wrap gap-2">
        @if ($recurring->isDue($today) && $user->can($module.'.create'))
            <form method="POST" action="{{ route('recurring-invoices.run', $recurring) }}">@csrf<button class="btn btn-primary"><i class="bi bi-play-circle me-1"></i>Make due invoices now</button></form>
        @endif
        @can($module.'.create')<a href="{{ route('recurring-invoices.edit', $recurring) }}" class="btn btn-outline-primary"><i class="bi bi-pencil me-1"></i>Alter</a>@endcan
        @can($module.'.delete')
            <form method="POST" action="{{ route('recurring-invoices.destroy', $recurring) }}" data-confirm="Delete the recurring invoice {{ $recurring->name }}? Invoices already made are kept.">@csrf @method('DELETE')<button class="btn btn-outline-danger">Delete</button></form>
        @endcan
    </div>
</div>

@php($lastFailed = $runs->first(fn ($r) => $r->status === 'failed' && $recurring->next_date && $r->date->toDateString() === $recurring->next_date->toDateString()))
@if ($lastFailed)
    <div class="alert alert-danger small">The invoice for {{ $lastFailed->date->format('d M Y') }} could not be made: {{ $lastFailed->message }} It will be tried again on the next run. Fix the cause, or alter the next date.</div>
@endif

<div class="row g-3 mb-3">
    <div class="col-12 col-lg-8">
        <div class="erp-card h-100">
            <div class="table-responsive">
                <table class="table align-middle mb-0 table-stack">
                    <thead class="table-light"><tr><th>Item / ledger</th><th class="text-end">Quantity</th><th class="text-end">Rate</th><th class="text-end">GST %</th></tr></thead>
                    <tbody>
                    @foreach ($recurring->template['items'] ?? [] as $item)
                        <tr>
                            <td data-label="Item / ledger">{{ $item['description'] ?: ($stockItems[$item['stock_item_id'] ?? 0] ?? $accounts[$item['account_id'] ?? 0] ?? '—') }}@if (! empty($item['batch_no']))<div class="small text-secondary">Batch {{ $item['batch_no'] }}</div>@endif</td>
                            <td data-label="Quantity" class="text-end">{{ \App\Support\Decimal::trim($item['quantity']) }} {{ $item['unit'] }}</td>
                            <td data-label="Rate" class="amount">{{ \App\Support\Money::format($item['rate']) }}@if ((float) ($item['discount_percent'] ?? 0) > 0)<div class="small text-secondary">less {{ \App\Support\Decimal::trim($item['discount_percent']) }}%</div>@endif</td>
                            <td data-label="GST %" class="text-end">{{ \App\Support\Decimal::trim($item['gst_rate']) }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            @if (! empty($recurring->template['narration']))<div class="p-3 border-top small"><span class="text-secondary">Narration:</span> {{ $recurring->template['narration'] }}</div>@endif
        </div>
    </div>
    <div class="col-12 col-lg-4">
        <div class="erp-card p-3 h-100 small">
            <div class="mb-2"><span class="text-secondary">Each invoice is</span><div>{{ \App\Models\RecurringVoucher::MODES[$recurring->mode] }}</div></div>
            <div class="mb-2"><span class="text-secondary">Amount (when set up)</span><div class="fw-semibold">{{ \App\Support\Money::format($recurring->total, true) }}</div></div>
            <div class="mb-2"><span class="text-secondary">Due</span><div>{{ $recurring->due_days !== null ? $recurring->due_days.' days after the invoice date' : "The party's credit period" }}</div></div>
            @if ($recurring->source)<div class="mb-2"><span class="text-secondary">Made from</span><div><a href="{{ route('invoices.show', $recurring->source) }}">{{ $recurring->source->displayNumber() }}</a></div></div>@endif
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
            <thead class="table-light"><tr><th>For date</th><th>Result</th><th>Invoice</th><th>Run</th></tr></thead>
            <tbody>
            @forelse ($runs as $run)
                <tr>
                    <td data-label="For date">{{ $run->date->format('d-m-Y') }}</td>
                    <td data-label="Result">@if ($run->status === 'created')<span class="badge text-bg-success">Made</span>@else<span class="badge text-bg-danger">Failed</span> {{ $run->message }}@endif</td>
                    <td data-label="Invoice">@if ($run->invoice)<a href="{{ route('invoices.show', $run->invoice) }}">{{ $run->invoice->displayNumber() }}</a> @include('accounting.vouchers._status', ['status' => $run->invoice->status()])@elseif ($run->status === 'created')<span class="text-secondary">deleted</span>@else — @endif</td>
                    <td data-label="Run">{{ $run->created_at?->format('d-m-Y H:i') }} · {{ $run->runner?->name ?? 'scheduler' }}</td>
                </tr>
            @empty
                <tr><td colspan="4" class="text-center text-secondary py-3">No invoices made yet.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
