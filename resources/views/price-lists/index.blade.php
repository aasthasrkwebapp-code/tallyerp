@extends('layouts.erp')

@section('title', 'Price Lists')

@section('content')
<div class="container-fluid py-3">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
        <div>
            <h2 class="h4 mb-1">Price Lists</h2>
            <p class="text-secondary mb-0">{{ $company->name }} · different rates for wholesale, retail, dealers…</p>
        </div>
        <a href="{{ route('companies.stock-items.index', $company) }}" class="btn btn-outline-secondary">Back to Stock Items</a>
    </div>

    @if (session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif
    @if ($errors->any())
        <div class="alert alert-danger">{{ $errors->first() }}</div>
    @endif

    <form method="POST" action="{{ route('companies.price-lists.store', $company) }}" class="row g-2 mb-3">
        @csrf
        <div class="col-8 col-md-5"><input name="name" class="form-control" placeholder="New price list, e.g. Wholesale" required maxlength="255" aria-label="Price list name"></div>
        <div class="col-4 col-md-2 d-grid"><button class="btn btn-primary">Create</button></div>
    </form>

    <div class="card border-0 shadow-sm">
        <div class="table-responsive">
            <table class="table align-middle mb-0">
                <thead class="table-light"><tr><th>Price list</th><th class="text-end">Items priced</th><th class="text-end">Parties using it</th><th></th></tr></thead>
                <tbody>
                    @forelse ($lists as $list)
                        <tr>
                            <td class="fw-semibold">{{ $list->name }}</td>
                            <td class="text-end">{{ $list->items_count }}</td>
                            <td class="text-end">{{ $list->parties_count }}</td>
                            <td class="text-end">
                                <div class="btn-group btn-group-sm">
                                    <a href="{{ route('companies.price-lists.edit', [$company, $list]) }}" class="btn btn-outline-primary">Edit prices</a>
                                    <form method="POST" action="{{ route('companies.price-lists.destroy', [$company, $list]) }}"
                                        onsubmit="return confirm(@js('Delete '.$list->name.'? Its parties go back to normal prices.'));">
                                        @csrf
                                        @method('DELETE')
                                        <button class="btn btn-outline-danger btn-sm">Delete</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="text-center text-secondary py-4">No price lists yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
    <p class="small text-secondary mt-2">Assign a price list to a customer on their ledger. Their bills then use these rates; items not in the list use the normal selling price.</p>
</div>
@endsection
