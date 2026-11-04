@extends('layouts.erp')

@section('title', 'Stock Summary')

@section('content')
@php
    $q = fn ($v) => rtrim(rtrim(number_format((float) $v, 3), '0'), '.');
    $m = fn ($v) => number_format((float) $v, 2);
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
        <div class="col-12 col-md-2 d-grid align-items-end">
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
                    </tr>
                </thead>
                <tbody>
                    @forelse ($rows as $row)
                        <tr class="{{ $row->closing_qty < 0 ? 'table-danger' : '' }}">
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
                        </tr>
                    @empty
                        <tr><td colspan="7" class="text-center text-secondary py-4">No stock items yet.</td></tr>
                    @endforelse
                </tbody>
                <tfoot class="fw-semibold">
                    <tr>
                        <td colspan="6">Grand Total</td>
                        <td class="text-end">{{ $m($rows->sum('closing_value')) }}</td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
    <p class="small text-secondary mt-2">Valued at weighted average cost. Rows in red have negative stock.</p>
</div>
@endsection
