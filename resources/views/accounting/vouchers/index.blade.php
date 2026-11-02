@extends('layouts.erp')

@section('title', 'Vouchers')

@section('content')
<div class="page-header">
    <div>
        <h1>Vouchers</h1>
        <p class="text-secondary mb-0">Journal, contra, payment, receipt and other accounting entries.</p>
    </div>
    @can('vouchers.create')
        <div class="dropdown">
            <button class="btn btn-primary dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false"><i class="bi bi-plus-lg me-1"></i>New voucher</button>
            <ul class="dropdown-menu dropdown-menu-end">
                @foreach ($types->where('is_active', true) as $type)
                    <li><a class="dropdown-item" href="{{ route('vouchers.create', ['type' => $type->id]) }}">{{ $type->name }}</a></li>
                @endforeach
            </ul>
        </div>
    @endcan
</div>

<form method="GET" class="erp-card p-3 mb-3">
    <div class="row g-2 align-items-end">
        <div class="col-6 col-md-2">
            <label class="form-label small" for="f-type">Type</label>
            <select id="f-type" name="type" class="form-select form-select-sm">
                <option value="">All</option>
                @foreach ($types as $type)<option value="{{ $type->id }}" @selected((int) ($filters['type'] ?? 0) === $type->id)>{{ $type->name }}</option>@endforeach
            </select>
        </div>
        <div class="col-6 col-md-2">
            <label class="form-label small" for="f-status">Status</label>
            <select id="f-status" name="status" class="form-select form-select-sm">
                <option value="">All</option>
                @foreach (\App\Models\Voucher::STATUSES as $k => $label)<option value="{{ $k }}" @selected(($filters['status'] ?? '') === $k)>{{ $label }}</option>@endforeach
            </select>
        </div>
        <div class="col-6 col-md-2">
            <label class="form-label small" for="f-from">From</label>
            <input id="f-from" type="date" name="from" value="{{ $filters['from'] ?? '' }}" class="form-control form-control-sm">
        </div>
        <div class="col-6 col-md-2">
            <label class="form-label small" for="f-to">To</label>
            <input id="f-to" type="date" name="to" value="{{ $filters['to'] ?? '' }}" class="form-control form-control-sm">
        </div>
        <div class="col-12 col-md-3">
            <label class="form-label small" for="f-q">Search</label>
            <input id="f-q" name="q" value="{{ $filters['q'] ?? '' }}" class="form-control form-control-sm" placeholder="Number, reference or narration">
        </div>
        <div class="col-12 col-md-1"><button class="btn btn-sm btn-primary w-100">Filter</button></div>
    </div>
</form>

<div class="erp-card">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0 table-stack">
            <thead class="table-light"><tr><th>Date</th><th>Type</th><th>Number</th><th>Particulars</th><th class="text-end">Amount</th><th>Status</th></tr></thead>
            <tbody>
            @forelse ($vouchers as $voucher)
                <tr>
                    <td data-label="Date" class="text-nowrap">{{ $voucher->date->format('d M Y') }}</td>
                    <td data-label="Type">{{ $voucher->type->name }}</td>
                    <td data-label="Number"><a href="{{ route('vouchers.show', $voucher) }}" class="fw-semibold">{{ $voucher->displayNumber() }}</a></td>
                    <td data-label="Particulars">
                        {{ $voucher->party?->name ?? $voucher->entries->first()?->account?->name }}
                        @if ($voucher->narration)<div class="small text-secondary text-truncate" style="max-width: 360px">{{ $voucher->narration }}</div>@endif
                    </td>
                    <td data-label="Amount" class="amount">{{ \App\Support\Money::format($voucher->total) }}</td>
                    <td data-label="Status">@include('accounting.vouchers._status', ['status' => $voucher->status])</td>
                </tr>
            @empty
                <tr><td colspan="6" class="text-center text-secondary py-4">No vouchers found.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
<div class="mt-3">{{ $vouchers->links() }}</div>
@endsection
