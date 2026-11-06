@extends('layouts.erp')

@section('title', 'Fixed Assets')

@section('content')
<div class="container-fluid py-3">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
        <div>
            <h2 class="h4 mb-1">Fixed Assets &amp; Depreciation</h2>
            <p class="text-secondary mb-0">{{ $company->name }} · written-down value as on {{ $date->format('d-m-Y') }}</p>
        </div>
        <form method="GET" class="d-flex gap-2">
            <input type="date" name="date" value="{{ $date->toDateString() }}" class="form-control form-control-sm">
            <button class="btn btn-sm btn-outline-primary">Show</button>
        </form>
    </div>

    @if (session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
    @if (session('error'))<div class="alert alert-danger">{{ session('error') }}</div>@endif
    @if ($errors->any())<div class="alert alert-danger"><ul class="mb-0">@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>@endif

    <form method="POST" action="{{ route('companies.fixed-assets.update', $company) }}" class="card border-0 shadow-sm mb-3">
        @csrf @method('PUT')
        <div class="table-responsive">
            <table class="table align-middle mb-0">
                <thead class="table-light"><tr><th>Asset</th><th class="text-end">Book value</th><th class="text-end" style="width: 9rem">Rate % a year</th><th class="text-end">Depreciation</th><th class="text-end">Value after</th></tr></thead>
                <tbody>
                    @forelse ($assets as $a)
                        <tr>
                            <td><a href="{{ route('companies.reports.ledger', [$company, 'account' => $a->account->id]) }}">{{ $a->account->name }}</a></td>
                            <td class="text-end">{{ inr($a->value) }}</td>
                            <td><input type="number" step="0.01" min="0" max="100" name="rates[{{ $a->account->id }}]" value="{{ $a->rate }}" class="form-control form-control-sm text-end"></td>
                            <td class="text-end">{{ $a->depreciation ? inr($a->depreciation) : '' }}</td>
                            <td class="text-end">{{ inr($a->value - $a->depreciation) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="text-center text-secondary py-4">No fixed-asset ledgers. Create ledgers under Fixed Assets (e.g. Machinery, Furniture, Computers).</td></tr>
                    @endforelse
                </tbody>
                @if ($assets->isNotEmpty())
                    <tfoot class="fw-semibold">
                        <tr>
                            <td>Total</td>
                            <td class="text-end">{{ inr($assets->sum('value')) }}</td>
                            <td class="text-end"><button class="btn btn-sm btn-outline-primary">Save rates</button></td>
                            <td class="text-end">{{ inr($assets->sum('depreciation')) }}</td>
                            <td class="text-end">{{ inr($assets->sum('value') - $assets->sum('depreciation')) }}</td>
                        </tr>
                    </tfoot>
                @endif
            </table>
        </div>
    </form>

    @if ($posted)
        <div class="alert alert-info">
            Depreciation for {{ $company->financial_year }} is posted:
            <a href="{{ route('companies.vouchers.show', [$company, $posted]) }}">Journal #{{ $posted->number }}</a> on {{ $posted->date->format('d-m-Y') }}, ₹ {{ inr($posted->amount) }}.
        </div>
    @elseif ($assets->sum('depreciation') > 0)
        <form method="POST" action="{{ route('companies.fixed-assets.post', $company) }}" class="d-flex flex-wrap gap-2 align-items-center"
            onsubmit="return confirm('Post depreciation of ₹ {{ inr($assets->sum('depreciation')) }}?')">
            @csrf
            <input type="hidden" name="date" value="{{ $date->toDateString() }}">
            <button class="btn btn-primary">Post depreciation journal on {{ $date->format('d-m-Y') }}</button>
            <span class="small text-secondary">Dr Depreciation, Cr each asset. Save any rate changes first.</span>
        </form>
    @endif
</div>
@endsection
