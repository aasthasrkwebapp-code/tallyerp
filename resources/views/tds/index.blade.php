@extends('layouts.erp')

@section('title', 'TDS Report')

@section('content')
<div class="container-fluid py-3">
    @include('reports._header', ['title' => 'TDS Report', 'subtitle' => $from->format('d-m-Y').' to '.$to->format('d-m-Y')])

    <div class="d-flex flex-wrap gap-2 mb-3 d-print-none">
        <a href="{{ route('companies.tds.create', $company) }}" class="btn btn-primary">+ Book Bill with TDS</a>
    </div>

    <form method="GET" class="row g-2 mb-3 d-print-none">
        <div class="col-6 col-md-3">
            <input type="date" name="from" value="{{ $from->toDateString() }}" class="form-control" aria-label="From">
        </div>
        <div class="col-6 col-md-3">
            <input type="date" name="to" value="{{ $to->toDateString() }}" class="form-control" aria-label="To">
        </div>
        <div class="col-12 col-md-2 d-grid"><button class="btn btn-outline-primary">Show</button></div>
    </form>

    <div class="row g-3 mb-3">
        <div class="col-12 col-lg-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white fw-semibold">By section</div>
                <table class="table mb-0">
                    <thead class="table-light">
                        <tr><th>Section</th><th class="text-end">Bills</th><th class="text-end">Amount paid/credited</th><th class="text-end">TDS</th></tr>
                    </thead>
                    <tbody>
                        @forelse ($sections as $row)
                            <tr>
                                <td>{{ $row->section }}</td>
                                <td class="text-end">{{ $row->count }}</td>
                                <td class="text-end">{{ inr($row->base) }}</td>
                                <td class="text-end fw-semibold">{{ inr($row->tds) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="text-center text-secondary py-3">No TDS in this period.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        <div class="col-12 col-lg-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white fw-semibold">Still to deposit with the government</div>
                <table class="table mb-0">
                    <tbody>
                        @forelse ($payable as $row)
                            <tr>
                                <td>
                                    <a href="{{ route('companies.reports.ledger', [$company, 'account' => $row->ledger->id]) }}">{{ $row->ledger->name }}</a>
                                </td>
                                <td class="text-end fw-semibold">{{ inr($row->balance) }}</td>
                            </tr>
                        @empty
                            <tr><td class="text-center text-secondary py-3">Nothing payable.</td></tr>
                        @endforelse
                    </tbody>
                </table>
                <div class="card-footer bg-white small text-secondary">
                    Record the deposit as a Payment voucher debiting the TDS Payable ledger.
                </div>
            </div>
        </div>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white fw-semibold">Deductions</div>
        <div class="table-responsive">
            <table class="table align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Date</th><th>Party</th><th>PAN</th><th>Section</th>
                        <th class="text-end">Amount</th><th class="text-end">Rate</th><th class="text-end">TDS</th><th>Voucher</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($deductions as $d)
                        <tr>
                            <td>{{ $d->voucher->date->format('d-m-Y') }}</td>
                            <td>{{ $d->party->name }}</td>
                            <td>{{ $d->party->pan ?: '—' }}</td>
                            <td>{{ \App\Support\Tds::SECTIONS[$d->section][0] ?? $d->section }}</td>
                            <td class="text-end">{{ inr($d->base_amount) }}</td>
                            <td class="text-end">{{ (float) $d->rate }}%</td>
                            <td class="text-end">{{ inr($d->tds_amount) }}</td>
                            <td><a href="{{ route('companies.vouchers.show', [$company, $d->voucher]) }}">Journal #{{ $d->voucher->number }}</a></td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="text-center text-secondary py-3">No deductions.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
