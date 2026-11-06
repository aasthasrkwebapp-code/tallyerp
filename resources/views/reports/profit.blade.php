@extends('layouts.erp')

@php $title = $by === 'items' ? 'Item-wise Profit' : 'Party-wise Profit'; @endphp

@section('title', $title)

@section('content')
<div class="container-fluid py-3">
    @include('reports._header', ['title' => $title, 'subtitle' => $from->format('d-m-Y').' to '.$to->format('d-m-Y')])

    <form method="GET" class="d-flex flex-wrap gap-2 align-items-end mb-3 d-print-none">
        <div class="btn-group">
            <a href="{{ route('companies.reports.profit', [$company, 'items', 'from' => $from->toDateString(), 'to' => $to->toDateString()]) }}" class="btn btn-sm {{ $by === 'items' ? 'btn-primary' : 'btn-outline-primary' }}">By item</a>
            <a href="{{ route('companies.reports.profit', [$company, 'parties', 'from' => $from->toDateString(), 'to' => $to->toDateString()]) }}" class="btn btn-sm {{ $by === 'parties' ? 'btn-primary' : 'btn-outline-primary' }}">By party</a>
        </div>
        <div><label class="form-label small mb-0">From</label><input type="date" name="from" value="{{ $from->toDateString() }}" class="form-control form-control-sm"></div>
        <div><label class="form-label small mb-0">To</label><input type="date" name="to" value="{{ $to->toDateString() }}" class="form-control form-control-sm"></div>
        <button class="btn btn-sm btn-primary">Show</button>
    </form>

    <div class="card border-0 shadow-sm">
        <div class="table-responsive">
            <table class="table align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>{{ $by === 'items' ? 'Item' : 'Party' }}</th>
                        @if ($by === 'items')<th class="text-end">Qty sold</th>@endif
                        <th class="text-end">Sale value</th><th class="text-end">Cost</th><th class="text-end">Profit</th><th class="text-end">Margin</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($rows as $row)
                        <tr>
                            <td>{{ $row->name }}</td>
                            @if ($by === 'items')<td class="text-end">{{ $row->id ? rtrim(rtrim(inr($row->quantity, 3), '0'), '.') : '' }}</td>@endif
                            <td class="text-end">{{ inr($row->value) }}</td>
                            <td class="text-end">{{ inr($row->cost) }}</td>
                            <td class="text-end fw-semibold {{ $row->profit < 0 ? 'text-danger' : '' }}">{{ inr($row->profit) }}</td>
                            <td class="text-end">{{ $row->margin === null ? '—' : number_format($row->margin, 2).' %' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="text-center text-secondary py-4">No sales in this period.</td></tr>
                    @endforelse
                </tbody>
                <tfoot class="fw-semibold">
                    <tr>
                        <td>Total</td>
                        @if ($by === 'items')<td></td>@endif
                        <td class="text-end">{{ inr($rows->sum('value')) }}</td>
                        <td class="text-end">{{ inr($rows->sum('cost')) }}</td>
                        <td class="text-end">{{ inr($rows->sum('profit')) }}</td>
                        <td class="text-end">{{ $rows->sum('value') ? number_format($rows->sum('profit') * 100 / $rows->sum('value'), 2).' %' : '' }}</td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
    <p class="small text-secondary mt-2">Cost is at weighted average cost. Service lines have no cost. Sales returns (credit notes) are deducted.</p>
</div>
@endsection
