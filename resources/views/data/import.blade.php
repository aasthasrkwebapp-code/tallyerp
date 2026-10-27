@extends('layouts.erp')

@section('title', 'Import')

@section('content')
<div class="page-header">
    <div>
        <h1>Import</h1>
        <p class="text-secondary mb-0">Bring in ledgers, customers, suppliers, stock items or vouchers from a CSV or Excel file.</p>
    </div>
</div>

@if (! $types)
    <div class="alert alert-warning">You do not have permission to create any of the records that can be imported.</div>
@else
<div class="row g-3">
    <div class="col-12 col-lg-5">
        <form method="POST" action="{{ route('imports.preview') }}" enctype="multipart/form-data" class="erp-card p-3">
            @csrf
            <div class="mb-3">
                <label for="type" class="form-label">What are you importing?</label>
                <select id="type" name="type" class="form-select @error('type') is-invalid @enderror" required>
                    @foreach ($types as $key => $t)
                        <option value="{{ $key }}" @selected(old('type', $selected) === $key)>{{ $t['label'] }}</option>
                    @endforeach
                </select>
            </div>
            <div class="mb-3">
                <label for="file" class="form-label">File (.csv or .xlsx, up to {{ number_format(\App\Services\Data\ImportService::MAX_ROWS) }} rows)</label>
                <input id="file" name="file" type="file" accept=".csv,.xlsx,.xls,.ods,text/csv" class="form-control @error('file') is-invalid @enderror" required>
                @error('file')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="form-check mb-3">
                <input class="form-check-input" type="checkbox" id="skip_duplicates" name="skip_duplicates" value="1" @checked(old('skip_duplicates'))>
                <label class="form-check-label" for="skip_duplicates">Skip rows that already exist (same name, or the same voucher)</label>
            </div>
            <button class="btn btn-primary">Check file</button>
            <p class="small text-secondary mt-2 mb-0">Nothing is saved yet: the next screen shows every problem found. The file is imported only when all rows are valid.</p>
        </form>
    </div>
    <div class="col-12 col-lg-7">
        <div class="erp-card">
            <div class="erp-card-header">Templates and columns</div>
            <div class="accordion accordion-flush" id="columns">
                @foreach ($types as $key => $t)
                    <div class="accordion-item">
                        <h2 class="accordion-header">
                            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#cols-{{ $key }}" aria-expanded="false" aria-controls="cols-{{ $key }}">{{ $t['label'] }}</button>
                        </h2>
                        <div id="cols-{{ $key }}" class="accordion-collapse collapse" data-bs-parent="#columns">
                            <div class="accordion-body">
                                <div class="d-flex flex-wrap gap-2 mb-2">
                                    <a href="{{ route('imports.template', [$key, 'xlsx']) }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-file-earmark-excel"></i> Excel template</a>
                                    <a href="{{ route('imports.template', [$key, 'csv']) }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-filetype-csv"></i> CSV template</a>
                                </div>
                                <ul class="small mb-0">
                                    @foreach (\App\Services\Data\ImportService::columns($key) as [$label, $required, $help])
                                        <li><strong>{{ $label }}</strong>@if ($required) <span class="text-danger">(required)</span>@endif @if ($help) — {{ $help }}@endif</li>
                                    @endforeach
                                </ul>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
        <p class="small text-secondary mt-2">Rows are checked with the same rules as the entry screens: unique names, GSTIN and PAN format, state codes, opening balances, balanced vouchers, locked periods. Files exported from <a href="{{ route('exports.index') }}">Export</a> use the same columns.</p>
    </div>
</div>
@endif
@endsection
