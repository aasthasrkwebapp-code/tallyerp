@extends('layouts.erp')

@section('title', 'Day Book')

@section('content')
<div class="page-header">
    <div>
        <h1>Day book</h1>
        <p class="text-secondary mb-0">All vouchers for the period, in date order.</p>
    </div>
</div>

@component('reports._period', ['from' => $from, 'to' => $to, 'csv' => true])
    <div class="col-6 col-md-2">
        <label class="form-label small" for="db-type">Voucher type</label>
        <select id="db-type" name="type" class="form-select form-select-sm">
            <option value="">All</option>
            @foreach ($types as $t)<option value="{{ $t->id }}" @selected((int) ($filters['type'] ?? 0) === $t->id)>{{ $t->name }}</option>@endforeach
        </select>
    </div>
    <div class="col-6 col-md-2">
        <div class="form-check">
            <input class="form-check-input" type="checkbox" id="db-all" name="all" value="1" @checked($filters['all'] ?? false)>
            <label class="form-check-label small" for="db-all">Include drafts &amp; cancelled</label>
        </div>
    </div>
@endcomponent

<div class="erp-card">
    <div class="table-responsive">
        <table class="table table-sm table-report align-middle mb-0 table-stack">
            <thead class="table-light"><tr><th>Date</th><th>Particulars</th><th>Type</th><th>Number</th><th class="text-end">Debit</th><th class="text-end">Credit</th></tr></thead>
            <tbody>
            @php($dr = 0)
            @php($cr = 0)
            @forelse ($vouchers as $v)
                @php($first = $v->entries->first())
                @php($vdr = $v->entries->sum(fn ($e) => \App\Support\Money::toPaise($e->debit)))
                @php($dr += $v->isPosted() ? $vdr : 0)
                @php($cr += $v->isPosted() ? $vdr : 0)
                <tr class="{{ $v->isPosted() ? '' : 'text-secondary' }}">
                    <td data-label="Date" class="text-nowrap">{{ $v->date->format('d M Y') }}</td>
                    <td data-label="Particulars">
                        {{ $v->party?->name ?? $first?->account?->name }}
                        @unless ($v->isPosted()) @include('accounting.vouchers._status', ['status' => $v->status]) @endunless
                        @if ($v->narration)<div class="small text-secondary">{{ Str::limit($v->narration, 90) }}</div>@endif
                    </td>
                    <td data-label="Type">{{ $v->type->name }}</td>
                    <td data-label="Number"><a href="{{ route('vouchers.show', $v) }}">{{ $v->displayNumber() }}</a></td>
                    <td data-label="Debit" class="amount">{{ \App\Support\Money::format($vdr, paiseInput: true) }}</td>
                    <td data-label="Credit" class="amount">{{ \App\Support\Money::format($vdr, paiseInput: true) }}</td>
                </tr>
            @empty
                <tr><td colspan="6" class="text-center text-secondary py-4">No vouchers in this period.</td></tr>
            @endforelse
            </tbody>
            <tfoot>
                <tr class="row-total"><td colspan="4" data-label="">Total (posted)</td><td class="amount" data-label="Debit">{{ \App\Support\Money::format($dr, paiseInput: true) }}</td><td class="amount" data-label="Credit">{{ \App\Support\Money::format($cr, paiseInput: true) }}</td></tr>
            </tfoot>
        </table>
    </div>
</div>
@endsection
