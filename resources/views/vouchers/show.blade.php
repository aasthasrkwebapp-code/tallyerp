@extends('layouts.erp')

@section('title', $voucher->typeLabel().' #'.$voucher->number)

@section('content')
<div class="container-fluid py-3">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
        <div>
            <h2 class="h4 mb-1">{{ $voucher->typeLabel() }} #{{ $voucher->number }}</h2>
            <p class="text-secondary mb-0">
                {{ $company->name }} · {{ $voucher->date->format('d-m-Y') }}
                @if ($voucher->reference)
                    · Ref: {{ $voucher->reference }}
                @endif
            </p>
        </div>
        <div class="d-flex flex-wrap gap-2">
            @if ($voucher->gstLines()->exists())
                <a href="{{ route('companies.gst-invoices.show', [$company, $voucher]) }}" class="btn btn-outline-primary">
                    View Invoice
                </a>
            @endif
            @unless ($voucher->gstLines()->exists() || $voucher->tdsDeductions()->exists() || $voucher->entries->contains(fn ($e) => $e->currency_code && (float) $e->foreign_amount == 0))
                <a href="{{ route('companies.vouchers.edit', [$company, $voucher]) }}" class="btn btn-outline-primary">Edit</a>
            @endunless
            <form method="POST" action="{{ route('companies.vouchers.destroy', [$company, $voucher]) }}"
                onsubmit="return confirm('Delete this voucher?');">
                @csrf
                @method('DELETE')
                <button class="btn btn-outline-danger">Delete</button>
            </form>
            <a href="{{ route('companies.vouchers.index', $company) }}" class="btn btn-outline-secondary">
                Back to Day Book
            </a>
        </div>
    </div>

    @if (session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif
    @if (session('error'))
        <div class="alert alert-danger">{{ session('error') }}</div>
    @endif

    <div class="card border-0 shadow-sm">
        <div class="table-responsive">
            <table class="table align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Ledger</th>
                        <th>Under</th>
                        <th class="text-end">Debit</th>
                        <th class="text-end">Credit</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($voucher->entries as $entry)
                        <tr>
                            <td>{{ $entry->account->name }}</td>
                            <td class="text-secondary">{{ $entry->account->group->name }}</td>
                            <td class="text-end">{{ $entry->debit > 0 ? inr((float) $entry->debit, 2) : '' }}</td>
                            <td class="text-end">{{ $entry->credit > 0 ? inr((float) $entry->credit, 2) : '' }}</td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot class="fw-semibold">
                    <tr>
                        <td colspan="2">Total</td>
                        <td class="text-end">{{ inr((float) $voucher->amount, 2) }}</td>
                        <td class="text-end">{{ inr((float) $voucher->amount, 2) }}</td>
                    </tr>
                </tfoot>
            </table>
        </div>
        @if ($voucher->narration)
            <div class="card-footer bg-white">
                <span class="text-secondary">Narration:</span> {{ $voucher->narration }}
            </div>
        @endif
    </div>
</div>
@endsection
