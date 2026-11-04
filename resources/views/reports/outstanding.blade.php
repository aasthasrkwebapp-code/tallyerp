@extends('layouts.erp')

@section('title', ucfirst($kind))

@section('content')
@php
    $m = fn ($v) => $v ? number_format((float) $v, 2) : '';
    $title = $kind === 'receivables' ? 'Receivables (Sundry Debtors)' : 'Payables (Sundry Creditors)';
@endphp
<div class="container-fluid py-3">
    @include('reports._header', ['title' => $title, 'subtitle' => 'As on '.$to->format('d-m-Y')])

    <div class="d-flex flex-wrap gap-2 mb-3 d-print-none">
        <a href="{{ route('companies.outstanding', [$company, 'receivables', 'to' => $to->toDateString()]) }}"
            class="btn btn-sm {{ $kind === 'receivables' ? 'btn-primary' : 'btn-outline-primary' }}">Receivables</a>
        <a href="{{ route('companies.outstanding', [$company, 'payables', 'to' => $to->toDateString()]) }}"
            class="btn btn-sm {{ $kind === 'payables' ? 'btn-primary' : 'btn-outline-primary' }}">Payables</a>
    </div>

    @include('reports._as-on')

    <div class="card border-0 shadow-sm">
        <div class="table-responsive">
            <table class="table align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Party</th>
                        @foreach (array_keys(\App\Http\Controllers\OutstandingController::BUCKETS) as $bucket)
                            <th class="text-end">{{ $bucket }} days</th>
                        @endforeach
                        <th class="text-end">Advance</th>
                        <th class="text-end">Total</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($parties as $row)
                        <tr>
                            <td>
                                <a href="{{ route('companies.reports.ledger', [$company, 'account' => $row->party->id, 'to' => $to->toDateString()]) }}">
                                    {{ $row->party->name }}
                                </a>
                            </td>
                            @foreach ($row->buckets as $bucket => $amount)
                                <td class="text-end {{ $bucket === '90+' && $amount > 0 ? 'text-danger' : '' }}">{{ $m($amount) }}</td>
                            @endforeach
                            <td class="text-end text-secondary">{{ $row->advance ? '('.number_format($row->advance, 2).')' : '' }}</td>
                            <td class="text-end fw-semibold">{{ number_format($row->total, 2) }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="text-center text-secondary py-4">Nothing outstanding.</td>
                        </tr>
                    @endforelse
                </tbody>
                <tfoot class="fw-semibold">
                    <tr>
                        <td>Total</td>
                        @foreach ($totals as $amount)
                            <td class="text-end">{{ number_format($amount, 2) }}</td>
                        @endforeach
                        <td></td>
                        <td class="text-end">{{ number_format($grandTotal, 2) }}</td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
    <p class="small text-secondary mt-2">Payments are matched against the oldest bills first.</p>
</div>
@endsection
