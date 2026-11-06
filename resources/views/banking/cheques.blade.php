@extends('layouts.erp')

@section('title', 'Cheque Register')

@section('content')
<div class="container-fluid py-3">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <div>
            <h2 class="h4 mb-1">Cheque Register</h2>
            <p class="text-secondary mb-0">{{ $company->name }} · cheque numbers and clearing dates come from <a href="{{ route('companies.banking.reconciliation', $company) }}">Bank Reconciliation</a></p>
        </div>
        <form method="GET" class="d-flex gap-2">
            <select name="direction" class="form-select form-select-sm" onchange="this.form.submit()">
                <option value="">Issued & received</option>
                <option value="issued" @selected($direction === 'issued')>Issued</option>
                <option value="received" @selected($direction === 'received')>Received</option>
            </select>
            <select name="status" class="form-select form-select-sm" onchange="this.form.submit()">
                <option value="">All statuses</option>
                @foreach (\App\Http\Controllers\ChequeController::STATUSES as $key => $label)
                    <option value="{{ $key }}" @selected($status === $key)>{{ $label }}</option>
                @endforeach
            </select>
        </form>
    </div>

    @if (session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
    @if ($errors->any())<div class="alert alert-danger"><ul class="mb-0">@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>@endif

    <div class="card border-0 shadow-sm">
        <div class="table-responsive">
            <table class="table align-middle mb-0">
                <thead class="table-light"><tr><th>Date</th><th>Cheque no.</th><th>Bank</th><th>Party</th><th>Type</th><th class="text-end">Amount</th><th>Status</th><th></th></tr></thead>
                <tbody>
                    @forelse ($cheques as $c)
                        @php
                            $issued = (float) $c->credit > 0;
                            $party = $c->voucher->entries->where('account_id', '!=', $c->account_id)->first()?->account?->name;
                        @endphp
                        <tr>
                            <td>{{ $c->voucher->date->format('d M Y') }}</td>
                            <td class="fw-semibold">{{ $c->instrument_no }}</td>
                            <td>{{ $c->account->name }}</td>
                            <td>{{ $party }}<div class="small text-secondary">{{ $c->voucher->typeLabel() }} #{{ $c->voucher->number }}</div></td>
                            <td>{{ $issued ? 'Issued' : 'Received' }}</td>
                            <td class="text-end">₹ {{ inr($issued ? $c->credit : $c->debit) }}</td>
                            <td>
                                @if ($c->bounced_voucher_id)
                                    <span class="badge text-bg-danger">Bounced</span>
                                @elseif ($c->bank_date)
                                    <span class="badge text-bg-success">Cleared {{ $c->bank_date->format('d M') }}</span>
                                @else
                                    <span class="badge text-bg-warning">Pending</span>
                                @endif
                            </td>
                            <td class="text-end">
                                @if (! $c->bounced_voucher_id && ! $c->bank_date)
                                    <form method="POST" action="{{ route('companies.cheques.bounce', [$company, $c]) }}" class="d-flex gap-1 justify-content-end" onsubmit="return confirm('Mark cheque {{ $c->instrument_no }} bounced? A journal will reverse the voucher.')">
                                        @csrf
                                        <input type="date" name="date" class="form-control form-control-sm" style="width: 9.5rem" value="{{ now()->toDateString() }}" required>
                                        <button class="btn btn-sm btn-outline-danger">Bounced</button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="text-center text-secondary py-4">No cheques. Add cheque numbers to bank entries on the reconciliation screen.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
