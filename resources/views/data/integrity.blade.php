@extends('layouts.erp')

@section('title', 'Integrity Check')

@php
    $icon = ['ok' => 'bi-check-circle-fill text-success', 'warning' => 'bi-exclamation-triangle-fill text-warning', 'error' => 'bi-x-circle-fill text-danger'];
    $errorsFound = collect($checks)->where('status', 'error')->count();
    $warnings = collect($checks)->where('status', 'warning')->count();
@endphp

@section('content')
<div class="page-header">
    <div>
        <h1>Integrity check</h1>
        <p class="text-secondary mb-0">Checks the books of {{ $currentCompany->displayName() }} directly against the database, as of {{ now()->format('d M Y H:i') }}.</p>
    </div>
    <div class="d-flex gap-2 no-print">
        <a href="{{ route('integrity') }}" class="btn btn-outline-secondary"><i class="bi bi-arrow-clockwise"></i> Run again</a>
        <button type="button" class="btn btn-outline-secondary" onclick="window.print()"><i class="bi bi-printer"></i> Print</button>
    </div>
</div>

<div class="alert {{ $errorsFound ? 'alert-danger' : ($warnings ? 'alert-warning' : 'alert-success') }}">
    @if ($errorsFound)
        {{ $errorsFound }} {{ \Illuminate\Support\Str::plural('check', $errorsFound) }} failed. Correct the vouchers listed below (alter or cancel and re-enter them).
    @elseif ($warnings)
        No errors. {{ $warnings }} {{ \Illuminate\Support\Str::plural('warning', $warnings) }} to review.
    @else
        All checks passed.
    @endif
</div>

<div class="erp-card">
    <ul class="list-group list-group-flush">
        @foreach ($checks as $check)
            <li class="list-group-item py-3">
                <div class="d-flex gap-3">
                    <i class="bi {{ $icon[$check->status] }} fs-5" aria-hidden="true"></i>
                    <div class="flex-grow-1">
                        <div class="fw-semibold">{{ $check->label }} <span class="visually-hidden">: {{ $check->status }}</span></div>
                        <div class="small text-secondary">{{ $check->message }}</div>
                        @if ($check->samples)
                            <div class="small mt-1 d-flex flex-wrap gap-2">
                                @foreach ($check->samples as $s)
                                    @if ($s['link'] === 'voucher')<a href="{{ route('vouchers.show', $s['id']) }}">{{ $s['label'] }}</a>
                                    @elseif ($s['link'] === 'invoice')<a href="{{ route('invoices.show', $s['id']) }}">{{ $s['label'] }}</a>
                                    @elseif ($s['link'] === 'stock')<a href="{{ route('stock-items.show', $s['id']) }}">{{ $s['label'] }}</a>
                                    @else<span>{{ $s['label'] }}</span>@endif
                                @endforeach
                            </div>
                        @endif
                    </div>
                </div>
            </li>
        @endforeach
    </ul>
</div>
@endsection
