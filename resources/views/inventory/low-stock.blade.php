@extends('layouts.erp')

@section('title', 'Low Stock')

@section('content')
@php $q = fn ($v) => rtrim(rtrim(inr((float) $v, 3), '0'), '.'); @endphp
<div class="container-fluid py-3">
    @include('reports._header', ['title' => 'Low Stock', 'subtitle' => 'Items at or below their reorder level · as on '.$on->format('d-m-Y')])

    <div class="card border-0 shadow-sm">
        <div class="table-responsive">
            <table class="table align-middle mb-0">
                <thead class="table-light"><tr><th>Item</th><th class="text-end">In stock</th><th class="text-end">Reorder level</th><th class="text-end">Short by</th><th class="text-end">Value of shortfall</th></tr></thead>
                <tbody>
                    @forelse ($rows as $row)
                        <tr class="{{ $row->closing_qty <= 0 ? 'table-danger' : '' }}">
                            <td class="fw-semibold"><a href="{{ route('companies.stock-items.edit', [$company, $row->item]) }}">{{ $row->item->name }}</a></td>
                            <td class="text-end">{{ $q($row->closing_qty) }} {{ $row->item->unit->symbol }}</td>
                            <td class="text-end">{{ $q($row->item->reorder_level) }}</td>
                            <td class="text-end">{{ $q($row->short) }}</td>
                            <td class="text-end">₹ {{ inr($row->short * $row->rate) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="text-center text-secondary py-4">All items are above their reorder level. Set a reorder level on an item to watch it here.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
