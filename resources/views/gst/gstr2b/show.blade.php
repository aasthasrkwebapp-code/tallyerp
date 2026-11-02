@extends('layouts.erp')

@section('title', 'GSTR-2B '.$return->label())

@section('content')
@php($m = fn (int $p) => \App\Support\Money::format($p, paiseInput: true))
@php($tax = fn (?array $a) => $a ? $a['igst'] + $a['cgst'] + $a['sgst'] + $a['cess'] : 0)
@php($badge = ['matched' => 'success', 'mismatch' => 'warning', 'probable' => 'info', 'missing_in_books' => 'danger', 'missing_in_2b' => 'secondary'])
<div class="page-header">
    <div>
        <h1>GSTR-2B · {{ $return->label() }}</h1>
        <p class="text-secondary mb-0">GSTIN {{ $return->gstin }} · {{ $return->document_count }} document(s)@if ($return->generated_on) · generated {{ $return->generated_on }}@endif</p>
    </div>
    <div class="d-flex flex-wrap gap-2 no-print">
        <a href="{{ request()->fullUrlWithQuery(['export' => 'csv']) }}" class="btn btn-outline-secondary"><i class="bi bi-filetype-csv"></i> CSV</a>
        <a href="{{ route('gst.gstr2b.index') }}" class="btn btn-outline-secondary">All statements</a>
    </div>
</div>

<div class="row g-2 mb-3">
    @foreach (\App\Services\Gst\Gstr2bMatching::STATUSES as $key => $label)
        @php($s = $result['summary'][$key])
        <div class="col-6 col-md-4 col-xl">
            <a href="{{ route('gst.gstr2b.show', [$return, 'status' => $status === $key ? null : $key]) }}" class="erp-card p-2 d-block text-decoration-none h-100 {{ $status === $key ? 'border-primary' : '' }}">
                <div class="small text-secondary">{{ $label }}</div>
                <div class="fs-5 fw-semibold text-body">{{ $s['count'] }}</div>
                <div class="small text-secondary">Tax {{ $m($key === 'missing_in_2b' ? $s['tax_books'] : $s['tax_2b']) }}</div>
            </a>
        </div>
    @endforeach
</div>
<p class="small text-secondary">Input tax credit in 2B: {{ $m($result['itc_2b']) }}@if ($result['itc_unavailable']) · not available as per 2B: {{ $m($result['itc_unavailable']) }}@endif. Amounts within ₹1 count as equal.</p>

<div class="erp-card">
    <div class="table-responsive">
        <table class="table table-sm align-middle mb-0 table-stack">
            <thead class="table-light"><tr><th>Status</th><th>Supplier</th><th>2B document</th><th class="text-end">Taxable / tax (2B)</th><th>Books</th><th class="text-end">Taxable / tax (books)</th><th>Differences</th></tr></thead>
            <tbody>
            @forelse ($rows as $r)
                <tr>
                    <td data-label="Status" class="text-nowrap"><span class="badge text-bg-{{ $badge[$r['status']] }}">{{ \App\Services\Gst\Gstr2bMatching::STATUSES[$r['status']] }}</span></td>
                    <td data-label="Supplier">{{ $r['party'] }}<div class="small text-secondary">{{ $r['gstin'] }}</div></td>
                    <td data-label="2B document">@if ($r['two_b']){{ $r['two_b']['number'] }} <span class="small text-secondary">{{ \Illuminate\Support\Carbon::parse($r['two_b']['date'])->format('d-m-Y') }}@if ($r['two_b']['type'] !== 'invoice') · {{ str_replace('_', ' ', $r['two_b']['type']) }}@endif @if ($r['two_b']['reverse_charge']) · RCM @endif @if (! $r['two_b']['itc_available']) · ITC not available @endif</span>@else — @endif</td>
                    <td data-label="Taxable / tax (2B)" class="text-end text-nowrap">@if ($r['two_b']){{ $m($r['two_b']['taxable']) }}<div class="small text-secondary">{{ $m($tax($r['two_b'])) }}</div>@else — @endif</td>
                    <td data-label="Books">@if ($r['books'])<a href="{{ route('invoices.show', $r['books']['invoice']) }}">{{ $r['books']['invoice']->voucher?->voucher_number }}</a> <span class="small text-secondary">{{ $r['books']['number'] }} · {{ \Illuminate\Support\Carbon::parse($r['books']['date'])->format('d-m-Y') }}</span>@else — @endif</td>
                    <td data-label="Taxable / tax (books)" class="text-end text-nowrap">@if ($r['books']){{ $m($r['books']['taxable']) }}<div class="small text-secondary">{{ $m($tax($r['books'])) }}</div>@else — @endif</td>
                    <td data-label="Differences" class="small">{{ implode('; ', $r['differences']) }}</td>
                </tr>
            @empty
                <tr><td colspan="7" class="text-center text-secondary py-4">Nothing to show.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
