{{-- Company dashboard. Expects $company and $f (App\Support\Dashboard::figures). --}}
@php
    $tiles = [
        ['Cash & Bank', $f->cash_bank, route('companies.banking.reconciliation', $company)],
        ['Receivables', $f->receivables, route('companies.outstanding', [$company, 'receivables'])],
        ['Payables', $f->payables, route('companies.outstanding', [$company, 'payables'])],
        ['Sales this year', $f->sales, route('companies.reports.profit-and-loss', $company)],
        [$f->net_profit >= 0 ? 'Net profit' : 'Net loss', abs($f->net_profit), route('companies.reports.profit-and-loss', $company)],
        ['Stock value', $f->stock_value, route('companies.inventory.summary', $company)],
        [$f->gst_payable >= 0 ? 'GST payable' : 'GST credit', abs($f->gst_payable), route('companies.gst.gstr3b', $company)],
    ];
    $maxSales = max(1, max($f->monthly_sales ?: [0]));
@endphp

<div class="small text-secondary mb-2">As on {{ $f->on->format('d-m-Y') }}</div>
@php $lowStock = \App\Http\Controllers\InventoryController::lowStockRows($company, \App\Http\Controllers\InventoryController::stockDate($company)); @endphp
@if ($lowStock->isNotEmpty())
    <div class="alert alert-warning d-flex justify-content-between align-items-center">
        <span><i class="bi bi-exclamation-triangle"></i> {{ $lowStock->count() }} {{ Str::plural('item', $lowStock->count()) }} at or below reorder level: {{ $lowStock->take(5)->map(fn ($r) => $r->item->name)->join(', ') }}{{ $lowStock->count() > 5 ? '…' : '' }}</span>
        <a href="{{ route('companies.inventory.summary', [$company, 'filter' => 'low']) }}" class="btn btn-sm btn-warning">View</a>
    </div>
@endif
<div class="row g-3 mb-3">
    @foreach ($tiles as [$label, $value, $href])
        <div class="col-6 col-md-4 col-xl-3">
            <a href="{{ $href }}" class="card border-0 shadow-sm h-100 text-decoration-none">
                <div class="card-body">
                    <div class="small text-secondary">{{ $label }}</div>
                    <div class="h5 mb-0 text-body">₹ {{ inr($value) }}</div>
                </div>
            </a>
        </div>
    @endforeach
</div>

<div class="row g-3 mb-4">
    <div class="col-12 col-xl-7">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white fw-semibold">Monthly sales · FY {{ $company->financial_year }}</div>
            <div class="card-body">
                <div class="d-flex align-items-end gap-1" style="height: 160px" role="img" aria-label="Monthly sales chart">
                    @foreach ($f->monthly_sales as $month => $amount)
                        <div class="flex-fill d-flex flex-column justify-content-end align-items-center h-100" title="{{ $month }}: ₹ {{ inr($amount) }}">
                            <div class="w-100 rounded-top bg-primary" style="height: {{ max(0, $amount) / $maxSales * 100 }}%; min-height: {{ $amount > 0 ? 2 : 0 }}px; opacity: .85"></div>
                        </div>
                    @endforeach
                </div>
                <div class="d-flex gap-1 mt-1">
                    @foreach (array_keys($f->monthly_sales) as $month)
                        <div class="flex-fill text-center small text-secondary">{{ $month }}</div>
                    @endforeach
                </div>
            </div>
        </div>
    </div>
    <div class="col-12 col-xl-5">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white fw-semibold">Recent vouchers</div>
            <ul class="list-group list-group-flush">
                @forelse ($f->recent as $voucher)
                    <li class="list-group-item d-flex justify-content-between gap-2">
                        <span class="text-truncate">
                            <a href="{{ route('companies.vouchers.show', [$company, $voucher]) }}">{{ $voucher->typeLabel() }} #{{ $voucher->number }}</a>
                            <small class="text-secondary">· {{ $voucher->date->format('d M') }} · {{ $voucher->entries->where('debit', '>', 0)->pluck('account.name')->first() }}</small>
                        </span>
                        <span class="text-nowrap">₹ {{ inr($voucher->amount) }}</span>
                    </li>
                @empty
                    <li class="list-group-item text-secondary">No vouchers yet.</li>
                @endforelse
            </ul>
        </div>
    </div>
</div>
