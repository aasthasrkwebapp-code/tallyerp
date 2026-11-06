@extends('layouts.erp')

@section('title', 'Day Book')

@section('content')
<div class="container-fluid py-3">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
        <div>
            <h2 class="h4 mb-1">Day Book</h2>
            <p class="text-secondary mb-0">{{ $company->name }}</p>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <a href="{{ route('companies.vouchers.create', $company) }}" class="btn btn-primary">
                + New Voucher
            </a>
            <a href="{{ route('companies.show', $company) }}" class="btn btn-outline-secondary">
                Back to Company
            </a>
        </div>
    </div>

    @if (session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif

    <form method="GET" class="row g-2 mb-3">
        <div class="col-12 col-md-4">
            <select name="type" class="form-select">
                <option value="">All voucher types</option>
                @foreach (\App\Models\Voucher::TYPES as $value => $label)
                    <option value="{{ $value }}" @selected(request('type') === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-6 col-md-3">
            <input type="date" name="from" value="{{ request('from') }}" class="form-control" aria-label="From">
        </div>
        <div class="col-6 col-md-3">
            <input type="date" name="to" value="{{ request('to') }}" class="form-control" aria-label="To">
        </div>
        <div class="col-12 col-md-2 d-grid">
            <button class="btn btn-outline-primary">Filter</button>
        </div>
        @if (request('type'))
            <div class="col-12">
                <div class="btn-group btn-group-sm">
                    <a href="{{ route('companies.vouchers.index', [$company, 'type' => request('type')]) }}" class="btn {{ $months ? 'btn-outline-secondary' : 'btn-secondary' }}">Voucher list</a>
                    <a href="{{ route('companies.vouchers.index', [$company, 'type' => request('type'), 'view' => 'monthly']) }}" class="btn {{ $months ? 'btn-secondary' : 'btn-outline-secondary' }}">Month-wise ({{ \App\Models\Voucher::TYPES[request('type')] ?? '' }} Register)</a>
                </div>
            </div>
        @endif
    </form>

    @if ($months)
    <div class="card border-0 shadow-sm">
        <div class="table-responsive">
            <table class="table align-middle mb-0">
                <thead class="table-light"><tr><th>Month</th><th class="text-end">Vouchers</th><th class="text-end">Taxable value</th><th class="text-end">GST</th><th class="text-end">Total</th></tr></thead>
                <tbody>
                    @foreach ($months as $row)
                        <tr>
                            <td>
                                @if ($row->count)
                                    <a href="{{ route('companies.vouchers.index', [$company, 'type' => request('type'), 'from' => $row->month->toDateString(), 'to' => $row->month->endOfMonth()->toDateString()]) }}">{{ $row->month->format('F Y') }}</a>
                                @else
                                    {{ $row->month->format('F Y') }}
                                @endif
                            </td>
                            <td class="text-end">{{ $row->count ?: '' }}</td>
                            <td class="text-end">{{ $row->count ? inr($row->taxable) : '' }}</td>
                            <td class="text-end">{{ $row->count ? inr($row->tax) : '' }}</td>
                            <td class="text-end">{{ $row->count ? inr($row->total) : '' }}</td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot class="fw-semibold">
                    <tr><td>Total</td><td class="text-end">{{ $months->sum('count') }}</td><td class="text-end">{{ inr($months->sum('taxable')) }}</td><td class="text-end">{{ inr($months->sum('tax')) }}</td><td class="text-end">{{ inr($months->sum('total')) }}</td></tr>
                </tfoot>
            </table>
        </div>
    </div>
    @else

    <div class="card border-0 shadow-sm">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Date</th>
                        <th>Type</th>
                        <th>No.</th>
                        <th>Particulars</th>
                        <th class="text-end">Amount</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($vouchers as $voucher)
                        <tr>
                            <td>{{ $voucher->date->format('d-m-Y') }}</td>
                            <td>{{ $voucher->typeLabel() }}</td>
                            <td>
                                <a href="{{ route('companies.vouchers.show', [$company, $voucher]) }}">
                                    #{{ $voucher->number }}
                                </a>
                            </td>
                            <td>
                                {{ $voucher->entries->where('debit', '>', 0)->pluck('account.name')->join(', ') }}
                                <span class="text-secondary">/</span>
                                {{ $voucher->entries->where('credit', '>', 0)->pluck('account.name')->join(', ') }}
                            </td>
                            <td class="text-end">{{ inr((float) $voucher->amount, 2) }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="text-center text-secondary py-4">
                                No vouchers yet.
                                <a href="{{ route('companies.vouchers.create', $company) }}">Record your first voucher</a>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-3">{{ $vouchers->links() }}</div>
    @endif
</div>
@endsection
