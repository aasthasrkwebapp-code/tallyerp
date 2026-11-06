@extends('layouts.erp')

@section('title', ucfirst($kind))

@section('content')
@php
    $m = fn ($v) => $v ? inr((float) $v, 2) : '';
    $title = $kind === 'receivables' ? 'Receivables (Sundry Debtors)' : 'Payables (Sundry Creditors)';
@endphp
<div class="container-fluid py-3">
    @include('reports._header', ['title' => $title, 'subtitle' => 'As on '.$to->format('d-m-Y')])

    @if (session('success'))<div class="alert alert-success d-print-none">{{ session('success') }}</div>@endif
    @if (session('error'))<div class="alert alert-danger d-print-none">{{ session('error') }}</div>@endif

    <div class="d-flex flex-wrap gap-2 mb-3 d-print-none">
        <a href="{{ route('companies.outstanding', [$company, 'receivables', 'to' => $to->toDateString()]) }}"
            class="btn btn-sm {{ $kind === 'receivables' ? 'btn-primary' : 'btn-outline-primary' }}">Receivables</a>
        <a href="{{ route('companies.outstanding', [$company, 'payables', 'to' => $to->toDateString()]) }}"
            class="btn btn-sm {{ $kind === 'payables' ? 'btn-primary' : 'btn-outline-primary' }}">Payables</a>
    </div>

    @include('reports._as-on')

    <div class="card border-0 shadow-sm">
        <div class="table-responsive">
            <table class="table align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Party</th>
                        @foreach (array_keys(\App\Http\Controllers\OutstandingController::BUCKETS) as $bucket)
                            <th class="text-end">{{ $bucket }} days</th>
                        @endforeach
                        <th class="text-end">Advance</th>
                        <th class="text-end">Overdue</th>
                        <th class="text-end">Total</th>
                        @if ($kind === 'receivables')<th class="text-end" title="Sales this year (before GST, net of returns) less cost of goods">Sales / profit (year)</th>@endif
                        @if ($kind === 'receivables')<th class="d-print-none"></th>@endif
                    </tr>
                </thead>
                <tbody>
                    @forelse ($parties as $row)
                        <tr>
                            <td>
                                <a href="{{ route('companies.reports.ledger', [$company, 'account' => $row->party->id, 'to' => $to->toDateString()]) }}">
                                    {{ $row->party->name }}
                                </a>
                            </td>
                            @foreach ($row->buckets as $bucket => $amount)
                                <td class="text-end {{ $bucket === '90+' && $amount > 0 ? 'text-danger' : '' }}">{{ $m($amount) }}</td>
                            @endforeach
                            <td class="text-end text-secondary">{{ $row->advance ? '('.inr($row->advance, 2).')' : '' }}</td>
                            <td class="text-end text-danger" title="{{ $row->party->credit_days !== null ? 'Credit '.$row->party->credit_days.' days' : 'Set credit days on the ledger' }}">{{ $row->overdue ? inr($row->overdue, 2) : '' }}</td>
                            <td class="text-end fw-semibold">
                                {{ inr($row->total, 2) }}
                                @if ($row->over_limit)
                                    <div><span class="badge text-bg-danger" title="Credit limit ₹ {{ inr($row->party->credit_limit) }}">Over limit</span></div>
                                @endif
                            </td>
                            @if ($kind === 'receivables')
                                <td class="text-end">
                                    @if ($row->sold_value)
                                        {{ inr($row->sold_value, 2) }}
                                        <div class="small {{ $row->profit < 0 ? 'text-danger' : 'text-success' }}">profit {{ inr($row->profit, 2) }}</div>
                                    @endif
                                </td>
                            @endif
                            @if ($kind === 'receivables')
                                <td class="text-end d-print-none">
                                    @if ($row->total > 0)
                                        <a href="{{ \App\Support\WhatsApp::link($row->party->phone, \App\Support\WhatsApp::reminderMessage($row->party, $row->total, $company->legal_name ?: $company->name, $company->upi_id)) }}"
                                            target="_blank" rel="noopener" class="btn btn-sm btn-outline-success text-nowrap"
                                            title="{{ $row->party->phone ? 'WhatsApp '.$row->party->phone : 'No mobile number on this ledger: you can pick the contact in WhatsApp' }}">
                                            <i class="bi bi-whatsapp"></i> Remind
                                        </a>
                                        <form method="POST" action="{{ route('companies.whatsapp.reminder', [$company, $row->party]) }}" class="d-inline">
                                            @csrf
                                            <input type="hidden" name="amount" value="{{ $row->total }}">
                                            <button class="btn btn-sm btn-outline-success" title="Send via WhatsApp Business API"><i class="bi bi-send"></i></button>
                                        </form>
                                        <form method="POST" action="{{ route('companies.sms.reminder', [$company, $row->party]) }}" class="d-inline">
                                            @csrf
                                            <input type="hidden" name="amount" value="{{ $row->total }}">
                                            <button class="btn btn-sm btn-outline-secondary" title="Send reminder by SMS"><i class="bi bi-chat-text"></i></button>
                                        </form>
                                    @endif
                                </td>
                            @endif
                        </tr>
                    @empty
                        <tr>
                            <td colspan="10" class="text-center text-secondary py-4">Nothing outstanding.</td>
                        </tr>
                    @endforelse
                </tbody>
                <tfoot class="fw-semibold">
                    <tr>
                        <td>Total</td>
                        @foreach ($totals as $amount)
                            <td class="text-end">{{ inr($amount, 2) }}</td>
                        @endforeach
                        <td></td>
                        <td class="text-end text-danger">{{ inr($parties->sum('overdue'), 2) }}</td>
                        <td class="text-end">{{ inr($grandTotal, 2) }}</td>
                        @if ($kind === 'receivables')<td class="text-end">{{ inr($parties->sum('sold_value'), 2) }}<div class="small">profit {{ inr($parties->sum('profit'), 2) }}</div></td>@endif
                        @if ($kind === 'receivables')<td class="d-print-none"></td>@endif
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
    <p class="small text-secondary mt-2">Payments are matched against the oldest bills first.</p>
</div>
@endsection
