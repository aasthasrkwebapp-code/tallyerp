@extends('layouts.erp')

@section('title', $list->name.' Prices')

@section('content')
<div class="container-fluid py-3">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
        <div>
            <h2 class="h4 mb-1">Price List: {{ $list->name }}</h2>
            <p class="text-secondary mb-0">{{ $company->name }}</p>
        </div>
        <a href="{{ route('companies.price-lists.index', $company) }}" class="btn btn-outline-secondary">Back</a>
    </div>

    @if (session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif
    @if ($errors->any())
        <div class="alert alert-danger">{{ $errors->first() }}</div>
    @endif

    <form method="POST" action="{{ route('companies.price-lists.update', [$company, $list]) }}">
        @csrf
        @method('PUT')
        <div class="mb-3" style="max-width: 420px">
            <label class="form-label" for="pl-name">Name</label>
            <input id="pl-name" name="name" value="{{ old('name', $list->name) }}" class="form-control" required maxlength="255">
        </div>

        <div class="card border-0 shadow-sm">
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead class="table-light">
                        <tr><th>Item</th><th class="text-end">Cost</th><th class="text-end">Normal selling price</th><th style="width: 200px" class="text-end">{{ $list->name }} price</th></tr>
                    </thead>
                    <tbody>
                        @forelse ($items as $item)
                            <tr>
                                <td>{{ $item->name }} <span class="small text-secondary">({{ $item->unit->symbol }})</span></td>
                                <td class="text-end text-secondary">{{ inr($item->opening_rate) }}</td>
                                <td class="text-end text-secondary">{{ $item->selling_price !== null ? inr($item->selling_price) : '—' }}</td>
                                <td>
                                    <input type="number" step="0.01" min="0" name="prices[{{ $item->id }}]"
                                        value="{{ old('prices.'.$item->id, isset($prices[$item->id]) ? (float) $prices[$item->id] : '') }}"
                                        class="form-control text-end" aria-label="{{ $list->name }} price for {{ $item->name }}">
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="text-center text-secondary py-4">No stock items yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="card-footer bg-white d-flex justify-content-between align-items-center">
                <small class="text-secondary">Leave blank to use the normal selling price.</small>
                <button class="btn btn-primary">Save Prices</button>
            </div>
        </div>
    </form>
</div>
@endsection
