@extends('layouts.erp')

@section('title', 'Voucher Types')

@section('content')
<div class="page-header">
    <div>
        <h1>Voucher types &amp; numbering</h1>
        <p class="text-secondary mb-0">Each type belongs to a voucher class that decides its accounting rules.</p>
    </div>
    @can('voucher_types.create')
        <a href="{{ route('voucher-types.create') }}" class="btn btn-primary"><i class="bi bi-plus-lg me-1"></i>New voucher type</a>
    @endcan
</div>

<div class="erp-card">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0 table-stack">
            <thead class="table-light"><tr><th>Name</th><th>Class</th><th>Numbering</th><th>Example</th><th class="text-end">Vouchers</th><th></th></tr></thead>
            <tbody>
            @foreach ($types as $type)
                <tr class="{{ $type->is_active ? '' : 'text-secondary' }}">
                    <td data-label="Name" class="fw-semibold">{{ $type->name }} @unless ($type->is_active)<span class="badge text-bg-secondary">Inactive</span>@endunless</td>
                    <td data-label="Class">{{ $type->baseLabel() }}</td>
                    <td data-label="Numbering">{{ $type->numbering === 'auto' ? 'Automatic'.($type->restart_yearly ? ', restarts yearly' : '') : 'Manual' }}</td>
                    <td data-label="Example"><code>{{ $type->isAutoNumbered() ? $type->formatNumber($type->starting_number) : '—' }}</code></td>
                    <td data-label="Vouchers" class="text-end">{{ $type->vouchers_count }}</td>
                    <td data-label="" class="text-end text-nowrap">
                        @can('vouchers.create')
                            @if ($type->is_active)<a href="{{ route('vouchers.create', ['type' => $type->id]) }}" class="btn btn-sm btn-outline-secondary">Enter</a>@endif
                        @endcan
                        @can('voucher_types.edit')
                            <a href="{{ route('voucher-types.edit', $type) }}" class="btn btn-sm btn-outline-primary" aria-label="Edit {{ $type->name }}"><i class="bi bi-pencil"></i></a>
                        @endcan
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
</div>
@endsection
