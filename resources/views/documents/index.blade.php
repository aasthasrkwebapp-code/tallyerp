@extends('layouts.erp')

@php $label = \App\Models\BusinessDocument::TYPES[$type][0]; @endphp

@section('title', \Illuminate\Support\Str::plural($label))

@section('content')
<div class="container-fluid py-3">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
        <div>
            <h2 class="h4 mb-1">{{ \Illuminate\Support\Str::plural($label) }}</h2>
            <p class="text-secondary mb-0">{{ $company->name }}</p>
        </div>
        <a href="{{ route('companies.documents.create', [$company, $type]) }}" class="btn btn-primary">+ New {{ $label }}</a>
    </div>

    <ul class="nav nav-tabs mb-3">
        @foreach (\App\Models\BusinessDocument::TYPES as $tabType => [$tabLabel])
            <li class="nav-item">
                <a class="nav-link {{ $tabType === $type ? 'active' : '' }}" href="{{ route('companies.documents.index', [$company, $tabType]) }}">{{ \Illuminate\Support\Str::plural($tabLabel) }}</a>
            </li>
        @endforeach
    </ul>

    @if (session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif

    <div class="d-flex flex-wrap gap-2 mb-3">
        <a href="{{ route('companies.documents.index', [$company, $type]) }}"
            class="btn btn-sm {{ request('status') ? 'btn-outline-primary' : 'btn-primary' }}">All</a>
        @foreach (\App\Models\BusinessDocument::STATUSES as $value => $name)
            <a href="{{ route('companies.documents.index', [$company, $type, 'status' => $value]) }}"
                class="btn btn-sm {{ request('status') === $value ? 'btn-primary' : 'btn-outline-primary' }}">{{ $name }}</a>
        @endforeach
    </div>

    <div class="card border-0 shadow-sm">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr><th>No.</th><th>Date</th><th>Party</th><th>Valid until</th><th>Status</th><th class="text-end">Amount</th></tr>
                </thead>
                <tbody>
                    @forelse ($documents as $document)
                        <tr>
                            <td><a href="{{ route('companies.documents.show', [$company, $type, $document]) }}">#{{ $document->number }}</a></td>
                            <td>{{ $document->date->format('d-m-Y') }}</td>
                            <td>{{ $document->party->name }}</td>
                            <td class="{{ $document->status === 'open' && $document->valid_until?->isPast() ? 'text-danger' : '' }}">
                                {{ $document->valid_until?->format('d-m-Y') ?? '—' }}
                            </td>
                            <td>
                                <span class="badge {{ ['open' => 'text-bg-primary', 'converted' => 'text-bg-success', 'cancelled' => 'text-bg-secondary'][$document->status] }}">
                                    {{ \App\Models\BusinessDocument::STATUSES[$document->status] }}
                                </span>
                            </td>
                            <td class="text-end">{{ inr($document->amount) }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="text-center text-secondary py-4">
                                No {{ strtolower(\Illuminate\Support\Str::plural($label)) }} yet.
                                <a href="{{ route('companies.documents.create', [$company, $type]) }}">Create the first one</a>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-3">{{ $documents->links() }}</div>
</div>
@endsection
