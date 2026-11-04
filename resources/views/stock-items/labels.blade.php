@extends('layouts.erp')

@section('title', 'Barcode Labels')

@section('content')
<style>
    .label-sheet { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 8px; }
    .barcode-label { border: 1px dashed #ccc; padding: 6px; text-align: center; background: #fff; break-inside: avoid; }
    .barcode-label svg { max-width: 100%; height: 50px; }
    @media print {
        .barcode-label { border-color: #eee; }
    }
</style>
<div class="container-fluid py-3">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4 d-print-none">
        <div>
            <h2 class="h4 mb-1">Barcode Labels</h2>
            <p class="text-secondary mb-0">{{ $company->name }}</p>
        </div>
        <div class="d-flex gap-2">
            @if ($labels->isNotEmpty())
                <button type="button" class="btn btn-primary" onclick="window.print()">Print {{ $labels->count() }} labels</button>
            @endif
            <a href="{{ route('companies.stock-items.index', $company) }}" class="btn btn-outline-secondary">Back to Stock Items</a>
        </div>
    </div>

    @if ($items->isEmpty())
        <div class="alert alert-warning">No stock items have a barcode yet. Edit an item and scan or generate its barcode.</div>
    @else
        <form method="GET" class="card border-0 shadow-sm mb-4 d-print-none">
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead class="table-light"><tr><th>Item</th><th>Barcode</th><th class="text-end">Price</th><th style="width: 140px">Labels</th></tr></thead>
                    <tbody>
                        @foreach ($items as $item)
                            <tr>
                                <td>{{ $item->name }}</td>
                                <td class="font-monospace">{{ $item->barcode }}</td>
                                <td class="text-end">{{ $item->selling_price !== null ? inr($item->selling_price) : '—' }}</td>
                                <td><input type="number" min="0" max="500" name="items[{{ $item->id }}]" value="{{ $copies[$item->id] ?? '' }}" class="form-control form-control-sm" aria-label="Labels for {{ $item->name }}"></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="card-footer bg-white text-end"><button class="btn btn-outline-primary">Show labels</button></div>
        </form>

        <div class="label-sheet">
            @foreach ($labels as $item)
                <div class="barcode-label">
                    <div class="small fw-semibold text-truncate">{{ $item->name }}</div>
                    @if (\App\Support\Barcode::encodable($item->barcode))
                        {!! \App\Support\Barcode::svg($item->barcode) !!}
                    @endif
                    <div class="small font-monospace">{{ $item->barcode }}</div>
                    @if ($item->selling_price !== null)
                        <div class="fw-semibold">₹ {{ inr($item->selling_price) }}</div>
                    @endif
                </div>
            @endforeach
        </div>
    @endif
</div>
@endsection
