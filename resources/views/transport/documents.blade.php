@extends('layouts.erp')

@section('title', 'Vehicle Documents')

@php
    $d = $editing;
    $v = fn ($name, $default = null) => old($name, $d?->{$name} instanceof \DateTimeInterface ? $d->{$name}->toDateString() : ($d?->{$name} ?? $default));
    $badge = ['expired' => 'text-bg-danger', 'due' => 'text-bg-warning', 'valid' => 'text-bg-success'];
    $canForm = $d ? auth()->user()->can('transport.edit') : auth()->user()->can('transport.create');
@endphp

@section('content')
<div class="page-header">
    <div>
        <h1>Vehicle documents</h1>
        <p class="text-secondary mb-0">Insurance, fitness, permits, PUC, road tax and driving licences, with reminders {{ $days }} days before they expire</p>
    </div>
    <div class="d-flex flex-wrap gap-2 no-print">
        <a href="{{ request()->fullUrlWithQuery(['export' => 'csv']) }}" class="btn btn-outline-secondary"><i class="bi bi-filetype-csv"></i> CSV</a>
        <a href="{{ request()->fullUrlWithQuery(['export' => 'xlsx']) }}" class="btn btn-outline-secondary"><i class="bi bi-file-earmark-excel"></i> Excel</a>
        <a href="{{ request()->fullUrlWithQuery(['export' => 'pdf']) }}" class="btn btn-outline-secondary"><i class="bi bi-file-earmark-pdf"></i> PDF</a>
    </div>
</div>

<div class="row g-3 mb-3">
    <div class="col-6 col-md-3"><a href="{{ route('vehicle-documents.index', ['status' => 'expired']) }}" class="erp-card stat-card d-block text-decoration-none text-body"><div class="stat-label">Expired</div><div class="stat-value {{ $counts['expired'] ? 'text-danger' : '' }}">{{ $counts['expired'] }}</div></a></div>
    <div class="col-6 col-md-3"><a href="{{ route('vehicle-documents.index', ['status' => 'due']) }}" class="erp-card stat-card d-block text-decoration-none text-body"><div class="stat-label">Due in {{ $days }} days</div><div class="stat-value">{{ $counts['due'] }}</div></a></div>
</div>

<form method="GET" class="erp-card p-3 mb-3 no-print">
    <div class="row g-2 align-items-end">
        <div class="col-6 col-sm-auto">
            <label class="form-label small" for="status">Show</label>
            <select id="status" name="status" class="form-select form-select-sm">
                @foreach (\App\Http\Controllers\Transport\VehicleDocumentController::STATUSES as $k => $label)<option value="{{ $k }}" @selected($status === $k)>{{ $label }}</option>@endforeach
            </select>
        </div>
        <div class="col-6 col-sm-auto">
            <label class="form-label small" for="vehicle">Vehicle</label>
            <select id="vehicle" name="vehicle" class="form-select form-select-sm">
                <option value="">All vehicles and drivers</option>
                @foreach ($vehicles as $veh)<option value="{{ $veh->id }}" @selected((int) ($filters['vehicle'] ?? 0) === $veh->id)>{{ $veh->number }}{{ $veh->is_active ? '' : ' (inactive)' }}</option>@endforeach
            </select>
        </div>
        <div class="col-12 col-sm-auto"><button class="btn btn-sm btn-primary w-100">Show</button></div>
    </div>
</form>

<div class="row g-3">
    <div class="col-12 col-xl-8">
        <div class="erp-card">
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0 table-stack">
                    <thead class="table-light"><tr><th>Vehicle / driver</th><th>Document</th><th>Number</th><th>Expires on</th><th class="text-end">Days left</th><th>Status</th><th class="text-end no-print"></th></tr></thead>
                    <tbody>
                    @forelse ($rows as $r)
                        <tr>
                            <td data-label="Vehicle / driver" class="fw-semibold">{{ $r->owner }}</td>
                            <td data-label="Document">{{ $r->label }}</td>
                            <td data-label="Number">{{ $r->number ?: '—' }}</td>
                            <td data-label="Expires on">{{ $r->expires_on->format('d-m-Y') }}</td>
                            <td data-label="Days left" class="text-end">{{ $r->days < 0 ? abs($r->days).' days ago' : $r->days }}</td>
                            <td data-label="Status"><span class="badge {{ $badge[$r->status] }}">{{ ['expired' => 'Expired', 'due' => 'Due soon', 'valid' => 'Valid'][$r->status] }}</span></td>
                            <td data-label="" class="text-end text-nowrap no-print">
                                @if ($r->document)
                                    @can('transport.create')<a href="{{ route('vehicle-documents.index', ['vehicle' => $r->vehicle_id, 'status' => 'all', 'renew' => $r->document->id]) }}#document-form" class="btn btn-sm btn-outline-primary">Renew</a>@endcan
                                    @can('transport.edit')<a href="{{ route('vehicle-documents.index', ['vehicle' => $r->vehicle_id, 'status' => 'all', 'edit' => $r->document->id]) }}#document-form" class="btn btn-sm btn-outline-secondary">Edit</a>@endcan
                                @elseif ($r->subject_type === 'driver')
                                    @can('transport.edit')<a href="{{ route('transport-masters.index', ['drivers', 'edit' => $r->subject_id]) }}" class="btn btn-sm btn-outline-secondary">Edit driver</a>@endcan
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="text-secondary text-center py-4">{{ $status === 'attention' ? 'Nothing has expired or is due in the next '.$days.' days.' : 'No documents.' }}</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        @if ($history->isNotEmpty())
            <div class="erp-card mt-3">
                <div class="p-3 pb-0"><h2 class="h6 fw-bold mb-0">All documents of {{ $vehicles->firstWhere('id', (int) $filters['vehicle'])?->number }}</h2></div>
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0 table-stack">
                        <thead class="table-light"><tr><th>Document</th><th>Number</th><th>Issued by</th><th>Valid</th><th class="text-end">Amount</th><th class="text-end no-print"></th></tr></thead>
                        <tbody>
                        @foreach ($history as $doc)
                            <tr>
                                <td data-label="Document">{{ $doc->typeLabel() }}</td>
                                <td data-label="Number">{{ $doc->number ?: '—' }}</td>
                                <td data-label="Issued by">{{ $doc->issuer ?: '—' }}</td>
                                <td data-label="Valid">{{ $doc->issued_on?->format('d-m-Y') ?? '…' }} to {{ $doc->expires_on->format('d-m-Y') }}</td>
                                <td data-label="Amount" class="amount">{{ $doc->amount !== null ? \App\Support\Money::format($doc->amount) : '—' }}</td>
                                <td data-label="" class="text-end text-nowrap no-print">
                                    @can('transport.edit')<a href="{{ route('vehicle-documents.index', ['vehicle' => $doc->vehicle_id, 'status' => 'all', 'edit' => $doc->id]) }}#document-form" class="btn btn-sm btn-outline-secondary">Edit</a>@endcan
                                    @can('transport.delete')
                                        <form method="POST" action="{{ route('vehicle-documents.destroy', $doc) }}" class="d-inline" data-confirm="Delete this {{ strtolower($doc->typeLabel()) }} record?">
                                            @csrf @method('DELETE')
                                            <button class="btn btn-sm btn-outline-danger" aria-label="Delete"><i class="bi bi-trash"></i></button>
                                        </form>
                                    @endcan
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endif
    </div>

    <div class="col-12 col-xl-4">
        @if ($canForm && $vehicles->isNotEmpty())
            @php($renew = request('renew') ? \App\Models\VehicleDocument::query()->find(request('renew')) : null)
            <form method="POST" id="document-form" action="{{ $d ? route('vehicle-documents.update', $d) : route('vehicle-documents.store') }}" class="erp-card p-3 mb-3">
                @csrf
                @if ($d) @method('PUT') @endif
                <h2 class="h6 fw-bold">{{ $d ? 'Edit document' : ($renew ? 'Renew '.strtolower($renew->typeLabel()) : 'Add document') }}</h2>
                <div class="mb-2">
                    <label for="vehicle_id" class="form-label">Vehicle</label>
                    <select id="vehicle_id" name="vehicle_id" class="form-select @error('vehicle_id') is-invalid @enderror" required>
                        <option value="">Select vehicle</option>
                        @foreach ($vehicles as $veh)<option value="{{ $veh->id }}" @selected((int) $v('vehicle_id', $renew?->vehicle_id ?? ($filters['vehicle'] ?? null)) === $veh->id)>{{ $veh->number }}</option>@endforeach
                    </select>
                    @error('vehicle_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="mb-2">
                    <label for="type" class="form-label">Document</label>
                    <select id="type" name="type" class="form-select">
                        @foreach (\App\Models\VehicleDocument::TYPES as $k => $label)<option value="{{ $k }}" @selected($v('type', $renew?->type) === $k)>{{ $label }}</option>@endforeach
                    </select>
                </div>
                <div class="row g-2 mb-2">
                    <div class="col-6">
                        <label for="number" class="form-label">Number</label>
                        <input id="number" name="number" value="{{ $v('number') }}" maxlength="60" class="form-control" placeholder="Policy / permit no.">
                    </div>
                    <div class="col-6">
                        <label for="issuer" class="form-label">Issued by</label>
                        <input id="issuer" name="issuer" value="{{ $v('issuer', $renew?->issuer) }}" maxlength="100" class="form-control" placeholder="Insurer, RTO">
                    </div>
                    <div class="col-6">
                        <label for="issued_on" class="form-label">Valid from</label>
                        <input id="issued_on" type="date" name="issued_on" value="{{ $v('issued_on', $renew?->expires_on->copy()->addDay()->toDateString()) }}" class="form-control @error('issued_on') is-invalid @enderror">
                        @error('issued_on')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-6">
                        <label for="expires_on" class="form-label">Valid till <span class="text-danger">*</span></label>
                        <input id="expires_on" type="date" name="expires_on" value="{{ $v('expires_on') }}" required class="form-control @error('expires_on') is-invalid @enderror">
                        @error('expires_on')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-6">
                        <label for="amount" class="form-label">Amount paid</label>
                        <input id="amount" name="amount" value="{{ $v('amount') }}" inputmode="decimal" class="form-control text-end @error('amount') is-invalid @enderror">
                        @error('amount')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                </div>
                <div class="mb-2">
                    <label for="notes" class="form-label">Notes</label>
                    <textarea id="notes" name="notes" rows="2" maxlength="1000" class="form-control">{{ $v('notes') }}</textarea>
                </div>
                <div class="d-flex gap-2 mt-3">
                    <button class="btn btn-primary flex-grow-1">{{ $d ? 'Save changes' : 'Save document' }}</button>
                    @if ($d || $renew)<a href="{{ route('vehicle-documents.index', array_filter(['vehicle' => $filters['vehicle'] ?? null])) }}" class="btn btn-outline-secondary">Cancel</a>@endif
                </div>
                <p class="small text-secondary mt-2 mb-0">Renewing adds the new document; the old one stays in the vehicle's history and stops counting for reminders.</p>
            </form>
        @elseif ($vehicles->isEmpty())
            <div class="erp-card p-3 mb-3 small text-secondary">Add a vehicle under <a href="{{ route('transport-masters.index', 'vehicles') }}">Vehicles, Drivers &amp; Routes</a> first. Driving licence expiry is entered on each driver.</div>
        @endif

        @can('transport.edit')
            <form method="POST" action="{{ route('vehicle-documents.settings') }}" class="erp-card p-3">
                @csrf @method('PUT')
                <h2 class="h6 fw-bold">Reminders</h2>
                <div class="mb-2">
                    <label for="document_reminder_days" class="form-label">Remind this many days before expiry</label>
                    <input id="document_reminder_days" type="number" min="1" max="365" name="document_reminder_days" value="{{ old('document_reminder_days', $days) }}" class="form-control @error('document_reminder_days') is-invalid @enderror">
                    @error('document_reminder_days')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="form-check mb-2">
                    <input type="hidden" name="document_reminder_email" value="0">
                    <input class="form-check-input" type="checkbox" id="document_reminder_email" name="document_reminder_email" value="1" @checked(app(\App\Support\CurrentCompany::class)->get()->setting('document_reminder_email', true))>
                    <label class="form-check-label" for="document_reminder_email">E-mail the company owner each morning about documents that become due or expire</label>
                </div>
                <button class="btn btn-outline-primary">Save reminder settings</button>
            </form>
        @endcan
    </div>
</div>
@endsection
