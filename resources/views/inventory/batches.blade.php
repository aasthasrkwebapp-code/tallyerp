@extends('layouts.erp')

@section('title', 'Batch-wise Stock')

@section('content')
@php
    $q = fn ($v) => rtrim(rtrim(number_format((float) $v, 3), '0'), '.');
@endphp
<div class="container-fluid py-3">
    @include('reports._header', ['title' => 'Batch-wise Stock', 'subtitle' => 'As on '.now()->format('d-m-Y')])

    <div class="d-flex flex-wrap gap-2 mb-3 d-print-none">
        @foreach (['in_stock' => 'In stock', 'expiring' => 'Expired / expiring in '.\App\Models\StockBatch::EXPIRY_WARNING_DAYS.' days', 'all' => 'All batches'] as $value => $label)
            <a href="{{ route('companies.inventory.batches', [$company, 'show' => $value]) }}"
                class="btn btn-sm {{ $show === $value ? 'btn-primary' : 'btn-outline-primary' }}">{{ $label }}</a>
        @endforeach
    </div>

    <div class="card border-0 shadow-sm">
        <div class="table-responsive">
            <table class="table align-middle mb-0">
                <thead class="table-light">
                    <tr><th>Item</th><th>Batch</th><th>Expiry</th><th class="text-end">In</th><th class="text-end">Out</th><th class="text-end">Left</th><th>Status</th></tr>
                </thead>
                <tbody>
                    @forelse ($rows as $row)
                        <tr class="{{ $row->left > 0 && $row->status === 'expired' ? 'table-danger' : ($row->left > 0 && $row->status === 'expiring' ? 'table-warning' : '') }}">
                            <td>{{ $row->batch->stockItem->name }}</td>
                            <td class="font-monospace">{{ $row->batch->batch_no }}</td>
                            <td>{{ $row->batch->expiry_date?->format('d-m-Y') ?? '—' }}</td>
                            <td class="text-end">{{ $q($row->in) }}</td>
                            <td class="text-end">{{ $q($row->out) }}</td>
                            <td class="text-end fw-semibold">{{ $q($row->left) }} {{ $row->batch->stockItem->unit->symbol }}</td>
                            <td>
                                @if ($row->status === 'expired')
                                    <span class="badge text-bg-danger">Expired</span>
                                @elseif ($row->status === 'expiring')
                                    <span class="badge text-bg-warning">Expires in {{ (int) now()->startOfDay()->diffInDays($row->batch->expiry_date) }} days</span>
                                @elseif ($row->left <= 0)
                                    <span class="badge text-bg-secondary">Sold out</span>
                                @else
                                    <span class="badge text-bg-success">OK</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="text-center text-secondary py-4">No batches to show. Turn on "Track batches" for an item and record a purchase with a batch number.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
