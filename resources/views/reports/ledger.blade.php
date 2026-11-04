@extends('layouts.erp')

@section('title', 'Ledger')

@section('content')
@php
    $drcr = fn (float $v) => inr(abs($v), 2).' '.($v < 0 ? 'Cr' : 'Dr');
@endphp
<div class="container-fluid py-3">
    @include('reports._header', [
        'title' => $account ? 'Ledger: '.$account->name : 'Ledger',
        'subtitle' => $from->format('d-m-Y').' to '.$to->format('d-m-Y'),
    ])

    <form method="GET" class="row g-2 mb-3 d-print-none">
        <div class="col-12 col-md-4">
            <label class="form-label small text-secondary mb-1" for="account">Ledger</label>
            <select id="account" name="account" class="form-select" required>
                <option value="">Select ledger</option>
                @foreach ($accounts as $option)
                    <option value="{{ $option->id }}" @selected($account?->id === $option->id)>{{ $option->name }}</option>
                @endforeach
            </select>
        </div>
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

    @if ($statement)
        <div class="card border-0 shadow-sm">
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Date</th>
                            <th>Particulars</th>
                            <th>Voucher</th>
                            <th class="text-end">Debit</th>
                            <th class="text-end">Credit</th>
                            <th class="text-end">Balance</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr class="fw-semibold">
                            <td>{{ $from->format('d-m-Y') }}</td>
                            <td colspan="4">Opening Balance</td>
                            <td class="text-end">{{ $drcr($statement['opening']) }}</td>
                        </tr>
                        @forelse ($statement['rows'] as $row)
                            <tr>
                                <td>{{ $row->voucher->date->format('d-m-Y') }}</td>
                                <td>{{ $row->particulars }}</td>
                                <td>
                                    <a href="{{ route('companies.vouchers.show', [$company, $row->voucher]) }}">
                                        {{ $row->voucher->typeLabel() }} #{{ $row->voucher->number }}
                                    </a>
                                </td>
                                <td class="text-end">{{ $row->debit ? inr($row->debit, 2) : '' }}</td>
                                <td class="text-end">{{ $row->credit ? inr($row->credit, 2) : '' }}</td>
                                <td class="text-end">{{ $drcr($row->balance) }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="text-center text-secondary py-3">No transactions in this period.</td>
                            </tr>
                        @endforelse
                    </tbody>
                    <tfoot class="fw-semibold">
                        <tr>
                            <td colspan="3">Closing Balance</td>
                            <td class="text-end">{{ inr($statement['debits'], 2) }}</td>
                            <td class="text-end">{{ inr($statement['credits'], 2) }}</td>
                            <td class="text-end">{{ $drcr($statement['closing']) }}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    @else
        <p class="text-secondary">Choose a ledger to see its transactions.</p>
    @endif
</div>
@endsection
