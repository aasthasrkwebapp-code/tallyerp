@extends('layouts.erp')

@section('title', 'Cost Centres')

@section('content')
<div class="page-header">
    <div>
        <h1>Cost centres</h1>
        <p class="text-secondary mb-0">Track income and expenses by department, project, vehicle or branch.</p>
    </div>
</div>

@unless ($enabled)
    <div class="alert alert-info">Cost centres are switched off for voucher entry. Turn them on in <a href="{{ route('settings.edit') }}">Company settings → Preferences</a>.</div>
@endunless

<div class="row g-3">
    <div class="col-12 col-xl-8">
        @foreach ($categories as $category)
            <div class="erp-card mb-3">
                <div class="erp-card-header">{{ $category->name }}</div>
                <div class="table-responsive">
                    <table class="table align-middle mb-0 table-stack">
                        <thead class="table-light"><tr><th>Cost centre</th><th>Code</th><th>Active</th><th class="text-end">Actions</th></tr></thead>
                        <tbody>
                        @forelse ($category->costCentres as $centre)
                            <tr>
                                <td data-label="Cost centre">{{ $centre->name }}</td>
                                <td data-label="Code">{{ $centre->code ?: '—' }}</td>
                                <td data-label="Active">{{ $centre->is_active ? 'Yes' : 'No' }}</td>
                                <td data-label="" class="text-end text-nowrap">
                                    @can('masters.edit')
                                        <form method="POST" action="{{ route('cost-centres.update', $centre) }}" class="d-inline">
                                            @csrf @method('PUT')
                                            <input type="hidden" name="name" value="{{ $centre->name }}">
                                            <input type="hidden" name="code" value="{{ $centre->code }}">
                                            <input type="hidden" name="cost_category_id" value="{{ $centre->cost_category_id }}">
                                            <input type="hidden" name="parent_id" value="{{ $centre->parent_id }}">
                                            <input type="hidden" name="is_active" value="{{ $centre->is_active ? 0 : 1 }}">
                                            <button class="btn btn-sm btn-outline-secondary">{{ $centre->is_active ? 'Deactivate' : 'Activate' }}</button>
                                        </form>
                                    @endcan
                                    @can('masters.delete')
                                        <form method="POST" action="{{ route('cost-centres.destroy', $centre) }}" class="d-inline" data-confirm="Delete cost centre {{ $centre->name }}?">
                                            @csrf @method('DELETE')
                                            <button class="btn btn-sm btn-outline-danger" aria-label="Delete {{ $centre->name }}"><i class="bi bi-trash"></i></button>
                                        </form>
                                    @endcan
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="text-secondary text-center py-3">No cost centres in this category.</td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        @endforeach
    </div>
    <div class="col-12 col-xl-4">
        @can('masters.create')
            <form method="POST" action="{{ route('cost-centres.store') }}" class="erp-card p-3 mb-3">
                @csrf
                <h2 class="h6 fw-bold">New cost centre</h2>
                <div class="mb-2">
                    <label for="cc_name" class="form-label">Name</label>
                    <input id="cc_name" name="name" class="form-control" required maxlength="100">
                </div>
                <div class="mb-2">
                    <label for="cc_code" class="form-label">Code</label>
                    <input id="cc_code" name="code" class="form-control" maxlength="50">
                </div>
                <div class="mb-3">
                    <label for="cc_category" class="form-label">Category</label>
                    <select id="cc_category" name="cost_category_id" class="form-select" required>
                        @foreach ($categories as $category)<option value="{{ $category->id }}">{{ $category->name }}</option>@endforeach
                    </select>
                </div>
                <button class="btn btn-primary w-100">Add cost centre</button>
            </form>
            <form method="POST" action="{{ route('cost-categories.store') }}" class="erp-card p-3">
                @csrf
                <h2 class="h6 fw-bold">New cost category</h2>
                <div class="mb-3">
                    <label for="cat_name" class="form-label">Name</label>
                    <input id="cat_name" name="name" class="form-control" required maxlength="100">
                </div>
                <button class="btn btn-outline-primary w-100">Add category</button>
            </form>
        @endcan
    </div>
</div>
@endsection
