@extends('layouts.erp')

@section('title', 'Serial Numbers')

@section('content')
<div class="container-fluid py-3">
    @include('reports._header', ['title' => 'Serial Numbers', 'subtitle' => $search !== '' ? 'Search: '.$search : 'All tracked units'])

    <form method="GET" class="row g-2 mb-3 d-print-none">
        <div class="col-12 col-md-5">
            <input name="q" value="{{ $search }}" class="form-control" placeholder="Serial / IMEI" aria-label="Serial number" autofocus>
        </div>
        <div class="col-8 col-md-4">
            <select name="item" class="form-select" aria-label="Item">
                <option value="">All items</option>
                @foreach ($items as $item)
                    <option value="{{ $item->id }}" @selected((string) request('item') === (string) $item->id)>{{ $item->name }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-4 col-md-2 d-grid"><button class="btn btn-outline-primary">Find</button></div>
    </form>

    <div class="card border-0 shadow-sm">
        <div class="table-responsive">
            <table class="table align-middle mb-0">
                <thead class="table-light">
                    <tr><th>Serial</th><th>Item</th><th>Bought</th><th>Sold</th><th>Status</th></tr>
                </thead>
                <tbody>
                    @forelse ($units as $unit)
                        <tr>
                            <td class="font-monospace">{{ $unit->serial }}</td>
                            <td>{{ $unit->stockItem->name }}</td>
                            <td>
                                @if ($unit->inVoucher)
                                    <a href="{{ route('companies.gst-invoices.show', [$company, $unit->inVoucher]) }}">{{ $unit->inVoucher->date->format('d-m-Y') }}</a>
                                    <div class="small text-secondary">{{ $unit->inVoucher->party?->name }}</div>
                                @else
                                    —
                                @endif
                            </td>
                            <td>
                                @if ($unit->outVoucher)
                                    <a href="{{ route('companies.gst-invoices.show', [$company, $unit->outVoucher]) }}">{{ $unit->outVoucher->date->format('d-m-Y') }}</a>
                                    <div class="small text-secondary">{{ $unit->outVoucher->party?->name }} · {{ $unit->outVoucher->typeLabel() }} #{{ $unit->outVoucher->number }}</div>
                                @else
                                    —
                                @endif
                            </td>
                            <td>
                                <span class="badge {{ $unit->inStock() ? 'text-bg-success' : 'text-bg-secondary' }}">{{ $unit->inStock() ? 'In stock' : 'Sold' }}</span>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="text-center text-secondary py-4">No serial numbers found.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
