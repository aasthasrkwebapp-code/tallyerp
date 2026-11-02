@extends('layouts.erp')

@section('title', 'E-invoices')

@section('content')
<div class="page-header">
    <div>
        <h1>E-invoices</h1>
        <p class="text-secondary mb-0">Posted sales invoices and credit notes to registered and overseas buyers. Download the JSON (schema 1.1) for the e-invoice portal's bulk or offline tool, then enter each IRN on its invoice.</p>
    </div>
</div>

<form method="GET" class="erp-card p-3 mb-3 no-print">
    <div class="row g-2 align-items-end">
        <div class="col-8 col-md-3"><label for="month" class="form-label">Month</label><input type="month" id="month" name="month" value="{{ $month }}" class="form-control"></div>
        <div class="col-4 col-md-2"><button class="btn btn-outline-primary w-100">Show</button></div>
    </div>
</form>

@error('invoices')<div class="alert alert-danger">@foreach ($errors->get('invoices') as $list)@foreach ((array) $list as $e)<div>{{ $e }}</div>@endforeach @endforeach</div>@enderror
@error('ids')<div class="alert alert-danger">{{ $message }}</div>@enderror

<form method="GET" action="{{ route('gst.e-invoices.json') }}">
    <div class="erp-card">
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0 table-stack">
                <thead class="table-light"><tr><th style="width: 2.5rem"></th><th>Document</th><th>Date</th><th>Buyer</th><th class="text-end">Value</th><th>IRN</th></tr></thead>
                <tbody>
                @forelse ($invoices as $i)
                    @php($problems = $service->problems($i))
                    @php($registered = $i->eInvoice && ! $i->eInvoice->cancelled_at)
                    <tr>
                        <td data-label="Include">@if (! $registered && ! $problems)<input type="checkbox" name="ids[]" value="{{ $i->id }}" class="form-check-input" aria-label="Include {{ $i->displayNumber() }}" checked>@endif</td>
                        <td data-label="Document"><a href="{{ route('invoices.show', $i) }}#e-invoice">{{ $i->displayNumber() }}</a>@if ($i->kind === 'credit_note') <span class="small text-secondary">credit note</span>@endif</td>
                        <td data-label="Date">{{ $i->date->format('d-m-Y') }}</td>
                        <td data-label="Buyer"><div>{{ $i->party_name }} <span class="small text-secondary">{{ $i->party_gstin ?: 'export' }}</span>@if ($problems)<div class="small text-danger">{{ implode(' ', $problems) }}</div>@endif</div></td>
                        <td data-label="Value" class="amount">{{ \App\Support\Money::format($i->grand_total) }}</td>
                        <td data-label="IRN">@if ($registered)<span class="small font-monospace">{{ \Illuminate\Support\Str::limit($i->eInvoice->irn, 16) }}</span>@elseif ($i->eInvoice)<span class="badge text-bg-secondary">Cancelled</span>@else<span class="badge text-bg-warning">Pending</span>@endif</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="text-center text-secondary py-4">No B2B or export invoices this month.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
    @if ($invoices->contains(fn ($i) => ! $i->eInvoice || $i->eInvoice->cancelled_at))
        <button class="btn btn-primary mt-3"><i class="bi bi-filetype-json me-1"></i>Download JSON for ticked documents</button>
    @endif
</form>
@endsection
