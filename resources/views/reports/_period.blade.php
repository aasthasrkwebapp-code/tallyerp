{{-- Period filter shared by reports. Pass $single = true for "as on" reports. --}}
<form method="GET" class="erp-card p-3 mb-3 no-print">
    @foreach (request()->except(['from', 'to', 'export', 'page']) as $k => $v)
        @if (! is_array($v))<input type="hidden" name="{{ $k }}" value="{{ $v }}">@endif
    @endforeach
    <div class="row g-2 align-items-end">
        @unless ($single ?? false)
            <div class="col-6 col-md-3">
                <label class="form-label small" for="p-from">From</label>
                <input id="p-from" type="date" name="from" value="{{ $from }}" class="form-control form-control-sm">
            </div>
        @endunless
        <div class="col-6 col-md-3">
            <label class="form-label small" for="p-to">{{ ($single ?? false) ? 'As on' : 'To' }}</label>
            <input id="p-to" type="date" name="to" value="{{ $to }}" class="form-control form-control-sm">
        </div>
        {{ $slot ?? '' }}
        <div class="col-12 col-md-auto d-flex gap-2">
            <button class="btn btn-sm btn-primary">Show</button>
            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="window.print()"><i class="bi bi-printer"></i> Print</button>
            @if (($csv ?? false) && auth()->user()->can('reports.export'))
                <a class="btn btn-sm btn-outline-secondary" href="{{ request()->fullUrlWithQuery(['export' => 'csv', 'from' => $from, 'to' => $to]) }}"><i class="bi bi-filetype-csv"></i> CSV</a>
                <a class="btn btn-sm btn-outline-secondary" href="{{ request()->fullUrlWithQuery(['export' => 'xlsx', 'from' => $from, 'to' => $to]) }}"><i class="bi bi-file-earmark-excel"></i> Excel</a>
            @endif
        </div>
    </div>
</form>
<div class="d-none d-print-block mb-2">
    <div class="fw-bold">{{ $currentCompany->displayName() }}</div>
    <div class="small">{{ ($single ?? false) ? 'As on '.\Illuminate\Support\Carbon::parse($to)->format('d M Y') : \Illuminate\Support\Carbon::parse($from)->format('d M Y').' to '.\Illuminate\Support\Carbon::parse($to)->format('d M Y') }}</div>
</div>
