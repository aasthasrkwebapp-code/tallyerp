@extends('layouts.erp')

@section('title', 'Currencies')

@section('content')
<div class="container-fluid py-3">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
        <div>
            <h2 class="h4 mb-1">Currencies</h2>
            <p class="text-secondary mb-0">{{ $company->name }} · base currency {{ $company->currency }}</p>
        </div>
        <a href="{{ route('companies.forex.revaluation', $company) }}" class="btn btn-outline-primary">Forex Revaluation</a>
    </div>

    @if (session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif
    @if (session('error'))
        <div class="alert alert-danger">{{ session('error') }}</div>
    @endif
    @if ($errors->any())
        <div class="alert alert-danger">
            <ul class="mb-0">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="POST" action="{{ route('companies.currencies.store', $company) }}" class="row g-2 mb-3">
        @csrf
        <div class="col-4 col-md-2">
            <input name="code" list="currency-codes" class="form-control text-uppercase" placeholder="USD" maxlength="3" required aria-label="Currency code">
            <datalist id="currency-codes">
                @foreach (\App\Models\Currency::COMMON as $code => [$symbol, $name])
                    <option value="{{ $code }}">{{ $name }}</option>
                @endforeach
            </datalist>
        </div>
        <div class="col-3 col-md-2"><input name="symbol" class="form-control" placeholder="Symbol" maxlength="5" aria-label="Symbol"></div>
        <div class="col-5 col-md-4"><input name="name" class="form-control" placeholder="Name (optional)" maxlength="255" aria-label="Name"></div>
        <div class="col-12 col-md-2 d-grid"><button class="btn btn-primary text-nowrap">Add currency</button></div>
    </form>

    <div class="card border-0 shadow-sm">
        <div class="table-responsive">
            <table class="table align-middle mb-0">
                <thead class="table-light"><tr><th>Code</th><th>Symbol</th><th>Name</th><th class="text-end">Ledgers</th><th></th></tr></thead>
                <tbody>
                    @forelse ($currencies as $currency)
                        <tr>
                            <td class="fw-semibold">{{ $currency->code }}</td>
                            <td>{{ $currency->symbol }}</td>
                            <td>{{ $currency->name }}</td>
                            <td class="text-end">{{ $currency->ledgers_count }}</td>
                            <td class="text-end">
                                @unless ($currency->ledgers_count)
                                    <form method="POST" action="{{ route('companies.currencies.destroy', [$company, $currency]) }}">
                                        @csrf
                                        @method('DELETE')
                                        <button class="btn btn-sm btn-outline-danger">Remove</button>
                                    </form>
                                @endunless
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="text-center text-secondary py-4">No foreign currencies yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
    <p class="small text-secondary mt-2">After adding a currency, choose it on a ledger (e.g. an overseas customer). Vouchers on that ledger then take the foreign amount and exchange rate.</p>
</div>
@endsection
