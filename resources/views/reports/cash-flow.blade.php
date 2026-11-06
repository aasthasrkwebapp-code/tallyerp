@extends('layouts.erp')

@section('title', 'Cash Flow')

@section('content')
<div class="container-fluid py-3">
    @include('reports._header', ['title' => 'Cash Flow', 'subtitle' => $from->format('d-m-Y').' to '.$to->format('d-m-Y')])
    @include('reports._statements-tabs')

    <form method="GET" class="d-flex flex-wrap gap-2 align-items-end mb-3 d-print-none">
        <div><label class="form-label small mb-0">From</label><input type="date" name="from" value="{{ $from->toDateString() }}" class="form-control form-control-sm"></div>
        <div><label class="form-label small mb-0">To</label><input type="date" name="to" value="{{ $to->toDateString() }}" class="form-control form-control-sm"></div>
        <button class="btn btn-sm btn-primary">Show</button>
    </form>

    <div class="card border-0 shadow-sm">
        <div class="table-responsive">
            <table class="table align-middle mb-0">
                <thead class="table-light"><tr><th>Particulars</th><th class="text-end">Inflow</th><th class="text-end">Outflow</th><th class="text-end">Net</th></tr></thead>
                <tbody>
                    <tr class="fw-semibold"><td>Opening cash & bank</td><td></td><td></td><td class="text-end">{{ inr($opening) }}</td></tr>
                    @foreach ($sections as $section)
                        <tr class="table-light fw-semibold"><td colspan="4">{{ $section->label }}</td></tr>
                        @forelse ($section->lines as $line)
                            <tr>
                                <td class="ps-4">{{ $line->account->name }}</td>
                                <td class="text-end">{{ $line->inflow ? inr($line->inflow) : '' }}</td>
                                <td class="text-end">{{ $line->outflow ? inr($line->outflow) : '' }}</td>
                                <td class="text-end">{{ inr($line->inflow - $line->outflow) }}</td>
                            </tr>
                        @empty
                            <tr><td class="ps-4 text-secondary" colspan="4">None</td></tr>
                        @endforelse
                        <tr class="fw-semibold">
                            <td class="ps-4">Net cash from {{ strtolower($section->label) }}</td>
                            <td class="text-end">{{ inr($section->inflow) }}</td>
                            <td class="text-end">{{ inr($section->outflow) }}</td>
                            <td class="text-end">{{ inr($section->net) }}</td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot class="fw-semibold">
                    <tr><td>Net increase / (decrease) in cash</td><td></td><td></td><td class="text-end">{{ inr($net) }}</td></tr>
                    <tr><td>Closing cash & bank</td><td></td><td></td><td class="text-end">{{ inr($closing) }}</td></tr>
                </tfoot>
            </table>
        </div>
    </div>
</div>
@endsection
