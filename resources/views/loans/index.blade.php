@extends('layouts.erp')

@section('title', 'Loans')

@section('content')
<div class="container-fluid py-3">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
        <div>
            <h2 class="h4 mb-1">Loans</h2>
            <p class="text-secondary mb-0">{{ $company->name }} · loans taken, EMIs and what is still owed</p>
        </div>
        <a href="{{ route('companies.loans.create', $company) }}" class="btn btn-primary">+ New Loan</a>
    </div>

    @if (session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
    @if (session('error'))<div class="alert alert-danger">{{ session('error') }}</div>@endif

    <div class="card border-0 shadow-sm">
        <div class="table-responsive">
            <table class="table align-middle mb-0">
                <thead class="table-light"><tr><th>Lender</th><th>Taken</th><th class="text-end">Principal</th><th class="text-end">Rate</th><th class="text-end">EMI</th><th class="text-end">Outstanding</th><th></th></tr></thead>
                <tbody>
                    @forelse ($loans as $loan)
                        <tr>
                            <td class="fw-semibold">{{ $loan->lender }}<div class="small text-secondary">{{ $loan->account->name }}</div></td>
                            <td>{{ $loan->start_date->format('d M Y') }}</td>
                            <td class="text-end">₹ {{ inr($loan->principal) }}</td>
                            <td class="text-end">{{ rtrim(rtrim($loan->annual_rate, '0'), '.') }}%</td>
                            <td class="text-end">₹ {{ inr($loan->emi) }} × {{ $loan->tenure_months }}</td>
                            <td class="text-end fw-semibold">₹ {{ inr($loan->outstanding()) }}</td>
                            <td class="text-end"><a href="{{ route('companies.loans.show', [$company, $loan]) }}" class="btn btn-sm btn-outline-primary">Open</a></td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="text-center text-secondary py-4">No loans yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
