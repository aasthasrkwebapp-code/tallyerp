@extends('layouts.erp')

@section('title', $labels['plural'])

@section('content')
<div class="page-header">
    <div>
        <h1>{{ $labels['plural'] }}</h1>
        <p class="text-secondary mb-0">Ledgers under {{ $labels['group'] }} with contact, GST and credit details.</p>
    </div>
    <div class="d-flex flex-wrap gap-2">
        @can('reports.view')
            <a href="{{ route('reports.outstanding', ['type' => $kind === 'customer' ? 'receivable' : 'payable']) }}" class="btn btn-outline-secondary"><i class="bi bi-hourglass-split me-1"></i>{{ $kind === 'customer' ? 'Receivables' : 'Payables' }}</a>
        @endcan
        @can($labels['module'].'.create')
            <a href="{{ route($labels['route'].'.create') }}" class="btn btn-primary" data-shortcut="c"><i class="bi bi-plus-lg me-1"></i>New {{ mb_strtolower($labels['singular']) }}</a>
        @endcan
    </div>
</div>

<form method="GET" class="erp-card p-3 mb-3">
    <div class="row g-2 align-items-end">
        <div class="col-12 col-md-6">
            <label class="form-label small" for="q">Search</label>
            <input id="q" name="q" value="{{ $filters['q'] ?? '' }}" class="form-control form-control-sm" placeholder="Name, alias, GSTIN or phone">
        </div>
        <div class="col-6 col-md-3">
            <div class="form-check">
                <input class="form-check-input" type="checkbox" name="inactive" value="1" id="inactive" @checked($filters['inactive'] ?? false)>
                <label class="form-check-label small" for="inactive">Include inactive</label>
            </div>
        </div>
        <div class="col-6 col-md-3"><button class="btn btn-sm btn-primary w-100">Filter</button></div>
    </div>
</form>

<div class="erp-card">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0 table-stack">
            <thead class="table-light"><tr><th>Name</th><th>GSTIN</th><th>State</th><th>Phone</th><th class="text-end">Balance</th><th class="text-end">Actions</th></tr></thead>
            <tbody>
            @forelse ($parties as $party)
                @php($d = $party->partyDetails)
                <tr class="{{ $party->is_active ? '' : 'text-secondary' }}">
                    <td data-label="Name">
                        <a href="{{ route($labels['route'].'.show', $party) }}" class="fw-semibold">{{ $party->name }}</a>
                        @if ($party->alias)<small class="text-secondary">({{ $party->alias }})</small>@endif
                        @unless ($party->is_active)<span class="badge text-bg-secondary">Inactive</span>@endunless
                        @if ($party->group->system_key !== ($kind === 'customer' ? 'sundry_debtors' : 'sundry_creditors'))<div class="small text-secondary">{{ $party->group->name }}</div>@endif
                    </td>
                    <td data-label="GSTIN" class="font-monospace small">{{ $d?->gstin ?? '—' }}</td>
                    <td data-label="State">{{ $d?->state ?? '—' }}</td>
                    <td data-label="Phone">{{ $d?->phone ?? '—' }}</td>
                    <td data-label="Balance" class="amount">{{ \App\Support\Money::drCr($balances[$party->id] ?? 0) }}</td>
                    <td data-label="" class="text-end text-nowrap">
                        @can($labels['module'].'.create')
                            @if ($party->is_active)
                                <a href="{{ route('invoices.create', ['kind' => $labels['invoice'], 'party' => $party->id]) }}" class="btn btn-sm btn-outline-success" aria-label="New invoice for {{ $party->name }}"><i class="bi bi-receipt"></i></a>
                            @endif
                        @endcan
                        @can($labels['module'].'.edit')
                            <a href="{{ route($labels['route'].'.edit', $party) }}" class="btn btn-sm btn-outline-primary" aria-label="Edit {{ $party->name }}"><i class="bi bi-pencil"></i></a>
                        @endcan
                    </td>
                </tr>
            @empty
                <tr><td colspan="6" class="text-center text-secondary py-4">No {{ mb_strtolower($labels['plural']) }} found.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
<div class="mt-3">{{ $parties->links() }}</div>
@endsection
