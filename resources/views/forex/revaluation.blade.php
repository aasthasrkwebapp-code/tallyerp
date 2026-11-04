@extends('layouts.erp')

@section('title', 'Forex Revaluation')

@section('content')
@php
    $net = $rows->sum(fn ($r) => $r->difference ?? 0);
@endphp
<div class="container-fluid py-3">
    @include('reports._header', ['title' => 'Forex Revaluation', 'subtitle' => 'As on '.$to->format('d-m-Y')])

    @if ($errors->any())
        <div class="alert alert-danger">
            <ul class="mb-0">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @if ($rows->isEmpty())
        <div class="alert alert-light border">
            No foreign-currency ledgers with balances.
            <a href="{{ route('companies.currencies.index', $company) }}">Set up currencies</a>
        </div>
    @else
        <form method="GET" class="card border-0 shadow-sm mb-3 d-print-none">
            <div class="card-body row g-2 align-items-end">
                <div class="col-6 col-md-3">
                    <label class="form-label small text-secondary mb-1" for="to">As on</label>
                    <input type="date" id="to" name="to" value="{{ $to->toDateString() }}" class="form-control">
                </div>
                @foreach ($codes as $code)
                    <div class="col-6 col-md-2">
                        <label class="form-label small text-secondary mb-1" for="rate-{{ $code }}">{{ $code }} rate (₹)</label>
                        <input type="number" step="0.000001" min="0" id="rate-{{ $code }}" name="rates[{{ $code }}]" value="{{ $rates[$code] ?? '' }}" class="form-control text-end" required>
                    </div>
                @endforeach
                <div class="col-12 col-md-2 d-grid"><button class="btn btn-outline-primary">Calculate</button></div>
            </div>
        </form>

        <div class="card border-0 shadow-sm">
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Ledger</th>
                            <th class="text-end">Foreign balance</th>
                            <th class="text-end">In books (₹)</th>
                            <th class="text-end">Book rate</th>
                            <th class="text-end">At closing rate (₹)</th>
                            <th class="text-end">Gain / (loss)</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $row)
                            <tr>
                                <td>{{ $row->account->name }}</td>
                                <td class="text-end">{{ $row->account->currency_code }} {{ inr($row->foreign) }}</td>
                                <td class="text-end">{{ inr($row->rupees) }}</td>
                                <td class="text-end">{{ $row->book_rate ?? '—' }}</td>
                                <td class="text-end">{{ $row->revalued !== null ? inr($row->revalued) : '' }}</td>
                                <td class="text-end fw-semibold {{ ($row->difference ?? 0) < 0 ? 'text-danger' : 'text-success' }}">
                                    {{ $row->difference === null ? '' : ($row->difference < 0 ? '('.inr(-$row->difference).')' : inr($row->difference)) }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                    @if (count($rates) === $codes->count())
                        <tfoot class="fw-semibold">
                            <tr>
                                <td colspan="5">Net exchange {{ $net >= 0 ? 'gain' : 'loss' }}</td>
                                <td class="text-end {{ $net < 0 ? 'text-danger' : 'text-success' }}">{{ inr(abs($net)) }}</td>
                            </tr>
                        </tfoot>
                    @endif
                </table>
            </div>
            @if (count($rates) === $codes->count() && $rows->contains(fn ($r) => ($r->difference ?? 0) != 0))
                <div class="card-footer bg-white text-end d-print-none">
                    <form method="POST" action="{{ route('companies.forex.post', $company) }}"
                        onsubmit="return confirm('Post this revaluation as a journal voucher?');">
                        @csrf
                        <input type="hidden" name="to" value="{{ $to->toDateString() }}">
                        @foreach ($rates as $code => $rate)
                            <input type="hidden" name="rates[{{ $code }}]" value="{{ $rate }}">
                        @endforeach
                        <button class="btn btn-primary">Post revaluation journal</button>
                    </form>
                </div>
            @endif
        </div>
        <p class="small text-secondary mt-2">Posting adjusts each ledger to its value at the closing rate, with the difference to "Exchange Gain / Loss". Opening balances are in rupees and are not revalued.</p>
    @endif
</div>
@endsection
