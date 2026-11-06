@extends('layouts.erp')

@section('title', $label)

@section('content')
<div class="container-fluid py-3">
    @include('reports._header', ['title' => $label, 'subtitle' => 'Financial year '.$company->financial_year])

    <div class="d-flex flex-wrap gap-2 mb-3 d-print-none">
        @foreach (\App\Http\Controllers\RegisterController::TYPES as $key => $name)
            <a href="{{ route('companies.reports.register', [$company, $key]) }}" class="btn btn-sm {{ $key === $type ? 'btn-primary' : 'btn-outline-primary' }}">{{ $name }}</a>
        @endforeach
    </div>

    <div class="card border-0 shadow-sm">
        <div class="table-responsive">
            <table class="table align-middle mb-0">
                <thead class="table-light"><tr><th>Month</th><th class="text-end">Vouchers</th><th class="text-end">Taxable value</th><th class="text-end">GST</th><th class="text-end">Total</th></tr></thead>
                <tbody>
                    @foreach ($months as $row)
                        <tr>
                            <td>
                                @if ($row->count)
                                    <a href="{{ route('companies.reports.register', [$company, $type, 'month' => $row->month->format('Y-m')]) }}">{{ $row->month->format('F Y') }}</a>
                                @else
                                    {{ $row->month->format('F Y') }}
                                @endif
                            </td>
                            <td class="text-end">{{ $row->count ?: '' }}</td>
                            <td class="text-end">{{ $row->count ? inr($row->taxable) : '' }}</td>
                            <td class="text-end">{{ $row->count ? inr($row->tax) : '' }}</td>
                            <td class="text-end">{{ $row->count ? inr($row->total) : '' }}</td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot class="fw-semibold">
                    <tr>
                        <td>Total</td>
                        <td class="text-end">{{ $months->sum('count') }}</td>
                        <td class="text-end">{{ inr($months->sum('taxable')) }}</td>
                        <td class="text-end">{{ inr($months->sum('tax')) }}</td>
                        <td class="text-end">{{ inr($months->sum('total')) }}</td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
</div>
@endsection
