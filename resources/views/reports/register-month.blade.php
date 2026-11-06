@extends('layouts.erp')

@section('title', $label.' · '.$month->format('F Y'))

@section('content')
<div class="container-fluid py-3">
    @include('reports._header', ['title' => $label, 'subtitle' => $month->format('F Y')])

    <a href="{{ route('companies.reports.register', [$company, $type]) }}" class="btn btn-sm btn-outline-secondary mb-3 d-print-none">← All months</a>

    <div class="card border-0 shadow-sm">
        <div class="table-responsive">
            <table class="table align-middle mb-0">
                <thead class="table-light"><tr><th>Date</th><th>No.</th><th>Party</th><th>Reference</th><th class="text-end">Taxable value</th><th class="text-end">GST</th><th class="text-end">Total</th></tr></thead>
                <tbody>
                    @foreach ($vouchers as $row)
                        @php $v = $row->voucher; @endphp
                        <tr>
                            <td>{{ $v->date->format('d-m-Y') }}</td>
                            <td><a href="{{ $v->gstLines->isNotEmpty() ? route('companies.gst-invoices.show', [$company, $v]) : route('companies.vouchers.show', [$company, $v]) }}">{{ $v->number }}</a></td>
                            <td>{{ $v->party?->name }}</td>
                            <td>{{ $v->reference }}</td>
                            <td class="text-end">{{ inr($row->taxable) }}</td>
                            <td class="text-end">{{ inr($row->tax) }}</td>
                            <td class="text-end">{{ inr($row->total) }}</td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot class="fw-semibold">
                    <tr>
                        <td colspan="4">Total ({{ $vouchers->count() }})</td>
                        <td class="text-end">{{ inr($vouchers->sum('taxable')) }}</td>
                        <td class="text-end">{{ inr($vouchers->sum('tax')) }}</td>
                        <td class="text-end">{{ inr($vouchers->sum('total')) }}</td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
</div>
@endsection
