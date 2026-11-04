@extends('layouts.erp')

@section('title', 'Trial Balance')

@section('content')
<div class="container-fluid py-3">
    @include('reports._header', ['title' => 'Trial Balance', 'subtitle' => 'As on '.$to->format('d-m-Y')])
    @include('reports._as-on')

    @if ($openingDifference != 0)
        <div class="alert alert-warning">
            Opening balances differ by {{ number_format(abs($openingDifference), 2) }}
            {{ $openingDifference > 0 ? 'Dr' : 'Cr' }}. Check the opening balances on your ledgers.
        </div>
    @endif

    <div class="card border-0 shadow-sm">
        <div class="table-responsive">
            <table class="table align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Particulars</th>
                        <th class="text-end" style="width: 180px">Debit</th>
                        <th class="text-end" style="width: 180px">Credit</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($groups as $groupName => $accounts)
                        <tr class="table-group-divider">
                            <td class="fw-semibold" colspan="3">{{ $groupName }}</td>
                        </tr>
                        @foreach ($accounts as $account)
                            <tr>
                                <td class="ps-4">
                                    <a href="{{ route('companies.reports.ledger', [$company, 'account' => $account->id, 'to' => $to->toDateString()]) }}">
                                        {{ $account->name }}
                                    </a>
                                </td>
                                <td class="text-end">{{ $account->balance > 0 ? number_format($account->balance, 2) : '' }}</td>
                                <td class="text-end">{{ $account->balance < 0 ? number_format(-$account->balance, 2) : '' }}</td>
                            </tr>
                        @endforeach
                    @empty
                        <tr>
                            <td colspan="3" class="text-center text-secondary py-4">No balances yet.</td>
                        </tr>
                    @endforelse
                </tbody>
                <tfoot class="fw-semibold">
                    <tr>
                        <td>Grand Total</td>
                        <td class="text-end">{{ number_format($totalDebit, 2) }}</td>
                        <td class="text-end">{{ number_format($totalCredit, 2) }}</td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
</div>
@endsection
