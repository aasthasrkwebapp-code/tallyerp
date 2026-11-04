@extends('layouts.erp')

@section('title', 'Balance Sheet')

@section('content')
<div class="container-fluid py-3">
    @include('reports._header', ['title' => 'Balance Sheet', 'subtitle' => 'As on '.$to->format('d-m-Y')])
    @include('reports._as-on')

    @if ($difference != 0)
        <div class="alert alert-warning">
            Difference in opening balances: {{ inr(abs($difference), 2) }}.
            Check the opening balances on your ledgers.
        </div>
    @endif

    <div class="row g-3">
        <div class="col-12 col-lg-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white fw-semibold">Liabilities</div>
                <table class="table mb-0">
                    <tbody>
                        @include('reports._section', ['sections' => $liabilities])
                        @if ($netProfit != 0)
                            <tr>
                                <td class="fw-semibold">Profit &amp; Loss A/c</td>
                                <td class="text-end fw-semibold">{{ inr($netProfit, 2) }}</td>
                            </tr>
                        @endif
                        @if ($difference > 0)
                            <tr class="text-danger">
                                <td>Difference in opening balances</td>
                                <td class="text-end">{{ inr($difference, 2) }}</td>
                            </tr>
                        @endif
                    </tbody>
                    <tfoot class="fw-semibold">
                        <tr><td>Total</td><td class="text-end">{{ inr($totalLiabilities + max($difference, 0), 2) }}</td></tr>
                    </tfoot>
                </table>
            </div>
        </div>
        <div class="col-12 col-lg-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white fw-semibold">Assets</div>
                <table class="table mb-0">
                    <tbody>
                        @include('reports._section', ['sections' => $assets])
                        @if ($difference < 0)
                            <tr class="text-danger">
                                <td>Difference in opening balances</td>
                                <td class="text-end">{{ inr(-$difference, 2) }}</td>
                            </tr>
                        @endif
                    </tbody>
                    <tfoot class="fw-semibold">
                        <tr><td>Total</td><td class="text-end">{{ inr($totalAssets + max(-$difference, 0), 2) }}</td></tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>
</div>
@endsection
