@extends('layouts.erp')

@section('title', 'Export')

@section('content')
<div class="page-header">
    <div>
        <h1>Export</h1>
        <p class="text-secondary mb-0">Download masters and vouchers as CSV or Excel. The columns match the <a href="{{ route('imports.index') }}">import</a>, so a file can be edited and imported again.</p>
    </div>
</div>

<div class="row g-3">
    @foreach ($types as $key => $t)
        <div class="col-12 col-md-6 col-xl-4">
            <form method="GET" action="{{ route('exports.download', $key) }}" class="erp-card p-3 h-100 d-flex flex-column">
                <h2 class="h6 fw-bold">{{ $t['label'] }}</h2>
                @if ($key === 'vouchers')
                    <div class="row g-2 mb-2">
                        <div class="col-6"><label class="form-label small" for="from-{{ $key }}">From</label><input id="from-{{ $key }}" type="date" name="from" value="{{ $from }}" class="form-control form-control-sm"></div>
                        <div class="col-6"><label class="form-label small" for="to-{{ $key }}">To</label><input id="to-{{ $key }}" type="date" name="to" value="{{ $to }}" class="form-control form-control-sm"></div>
                    </div>
                    <p class="small text-secondary">Posted vouchers, one row per ledger line.</p>
                @endif
                <div class="mt-auto d-flex gap-2">
                    <button name="format" value="xlsx" class="btn btn-sm btn-outline-primary"><i class="bi bi-file-earmark-excel"></i> Excel</button>
                    <button name="format" value="csv" class="btn btn-sm btn-outline-secondary"><i class="bi bi-filetype-csv"></i> CSV</button>
                </div>
            </form>
        </div>
    @endforeach
</div>
<p class="small text-secondary mt-3">Reports have their own CSV and Excel buttons. For a complete copy of the company use <a href="{{ route('backups.index') }}">Backup &amp; Restore</a>.</p>
@endsection
