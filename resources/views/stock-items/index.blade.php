@extends('layouts.erp')

@section('title', 'Stock Items')

@section('content')
<div class="container-fluid py-3">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
        <div>
            <h2 class="h4 mb-1">Stock Items</h2>
            <p class="text-secondary mb-0">{{ $company->name }}</p>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <a href="{{ route('companies.stock-items.create', $company) }}" class="btn btn-primary">+ Create Item</a>
            <a href="{{ route('companies.inventory.setup', $company) }}" class="btn btn-outline-primary">Units, Groups &amp; Godowns</a>
            <a href="{{ route('companies.manufacturing.index', $company) }}" class="btn btn-outline-primary">Manufacturing</a>
            <a href="{{ route('companies.price-lists.index', $company) }}" class="btn btn-outline-primary">Price Lists</a>
            <a href="{{ route('companies.stock-items.labels', $company) }}" class="btn btn-outline-primary">Barcode Labels</a>
            <a href="{{ route('companies.inventory.batches', $company) }}" class="btn btn-outline-primary">Batches &amp; Expiry</a>
            <a href="{{ route('companies.inventory.serials', $company) }}" class="btn btn-outline-primary">Serial Numbers</a>
            <a href="{{ route('companies.inventory.summary', $company) }}" class="btn btn-outline-primary">Stock Summary</a>
            <a href="{{ route('companies.show', $company) }}" class="btn btn-outline-secondary">Back to Company</a>
        </div>
    </div>

    @if (session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif
    @if (session('error'))
        <div class="alert alert-danger">{{ session('error') }}</div>
    @endif
    @if ($errors->any())
        <div class="alert alert-danger"><ul class="mb-0">@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>
    @endif

    <div class="card border-0 shadow-sm">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Item</th>
                        <th>Group</th>
                        <th>HSN/SAC</th>
                        <th class="text-end">GST %</th>
                        <th class="text-end">Opening</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($items as $item)
                        <tr>
                            <td>
                                <div class="fw-semibold">{{ $item->name }}</div>
                                @if ($item->barcode)<small class="text-secondary font-monospace">{{ $item->barcode }}</small>@endif
                            </td>
                            <td>{{ $item->group?->name ?? '—' }}</td>
                            <td>{{ $item->hsn_sac ?: '—' }}</td>
                            <td class="text-end">{{ $item->gst_rate === null ? '—' : (float) $item->gst_rate.'%' }}</td>
                            <td class="text-end">
                                {{ (float) $item->opening_quantity }} {{ $item->unit->symbol }}
                                <span class="text-secondary small">@ {{ inr((float) $item->opening_rate, 2) }}</span>
                            </td>
                            <td class="text-end">
                                <div class="btn-group btn-group-sm" role="group">
                                    <a href="{{ route('companies.stock-items.edit', [$company, $item]) }}" class="btn btn-outline-primary">Edit</a>
                                    <form method="POST" action="{{ route('companies.stock-items.destroy', [$company, $item]) }}"
                                        onsubmit="return confirm(@js('Delete stock item '.$item->name.'?'));">
                                        @csrf
                                        @method('DELETE')
                                        <button class="btn btn-outline-danger btn-sm">Delete</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="text-center text-secondary py-4">
                                No stock items yet.
                                <a href="{{ route('companies.stock-items.create', $company) }}">Create your first item</a>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    @if ($godowns->isNotEmpty())
        @include('stock-items._godowns')
    @endif
</div>
@endsection
