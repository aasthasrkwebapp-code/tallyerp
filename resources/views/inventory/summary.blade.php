@extends('layouts.erp')

@section('title', 'Stock Summary')

@section('content')
@php
    $q = fn ($v) => rtrim(rtrim(inr((float) $v, 3), '0'), '.');
    $m = fn ($v) => inr((float) $v, 2);
@endphp
<div class="container-fluid py-3">
    @include('reports._header', ['title' => 'Stock Summary', 'subtitle' => $from->format('d-m-Y').' to '.$to->format('d-m-Y')])

    <form method="GET" class="row g-2 mb-3 d-print-none">
        <div class="col-6 col-md-3">
            <label class="form-label small text-secondary mb-1" for="from">From</label>
            <input type="date" id="from" name="from" value="{{ $from->toDateString() }}" class="form-control">
        </div>
        <div class="col-6 col-md-3">
            <label class="form-label small text-secondary mb-1" for="to">To</label>
            <input type="date" id="to" name="to" value="{{ $to->toDateString() }}" class="form-control">
        </div>
        <div class="col-6 col-md-2">
            <label class="form-label small text-secondary mb-1" for="filter">Show</label>
            <select id="filter" name="filter" class="form-select">
                <option value="">All items</option>
                <option value="low" @selected($lowOnly)>At or below reorder level</option>
            </select>
        </div>
        <div class="col-6 col-md-2 d-grid align-items-end">
            <button class="btn btn-outline-primary">Show</button>
        </div>
    </form>

    <div class="card border-0 shadow-sm">
        <div class="table-responsive">
            <table class="table align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Item</th>
                        <th class="text-end">Opening</th>
                        <th class="text-end">Inwards</th>
                        <th class="text-end">Outwards</th>
                        <th class="text-end">Closing qty</th>
                        <th class="text-end">Avg. rate</th>
                        <th class="text-end">Closing value</th>
                        <th class="text-end">Reorder at</th>
                        <th class="text-end">Sold value</th>
                        <th class="text-end">Profit</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($rows as $row)
                        <tr class="{{ $row->closing_qty < 0 ? 'table-danger' : ($row->low ? 'table-warning' : '') }}">
                            <td>
                                <div class="fw-semibold">{{ $row->item->name }}</div>
                                <small class="text-secondary">{{ $row->item->group?->name ?? 'Primary' }}</small>
                            </td>
                            <td class="text-end">{{ $q($row->opening_qty) }} {{ $row->item->unit->symbol }}</td>
                            <td class="text-end">{{ $q($row->inwards) }}</td>
                            <td class="text-end">{{ $q($row->outwards) }}</td>
                            <td class="text-end fw-semibold">{{ $q($row->closing_qty) }} {{ $row->item->unit->symbol }}</td>
                            <td class="text-end">{{ $m($row->rate) }}</td>
                            <td class="text-end">{{ $m($row->closing_value) }}</td>
                            <td class="text-end">{{ $row->item->reorder_level !== null ? $q($row->item->reorder_level) : '' }}</td>
                            <td class="text-end">{{ $row->sold_value ? $m($row->sold_value) : '' }}</td>
                            <td class="text-end {{ $row->profit < 0 ? 'text-danger' : '' }}" title="{{ $row->sold_value ? 'Cost '.$m($row->cost).' · margin '.number_format($row->profit * 100 / $row->sold_value, 1).'%' : '' }}">{{ $row->sold_value ? $m($row->profit) : '' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="10" class="text-center text-secondary py-4">{{ $lowOnly ? 'No items at or below their reorder level.' : 'No stock items yet.' }}</td></tr>
                    @endforelse
                </tbody>
                <tfoot class="fw-semibold">
                    <tr>
                        <td colspan="6">Grand Total</td>
                        <td class="text-end">{{ $m($rows->sum('closing_value')) }}</td>
                        <td></td>
                        <td class="text-end">{{ $m($rows->sum('sold_value')) }}</td>
                        <td class="text-end">{{ $m($rows->sum('profit')) }}</td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
    <p class="small text-secondary mt-2">Valued at weighted average cost. Rows in red have negative stock; yellow rows are at or below their reorder level. Profit = sale value (net of returns, before GST) less cost at average rate.</p>
</div>
@endsection
