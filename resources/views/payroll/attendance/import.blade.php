@extends('layouts.erp')

@section('title', 'Import Attendance')

@section('content')
<div class="page-header">
    <div>
        <h1>Import attendance</h1>
        <p class="text-secondary mb-0">Load days from a biometric or HR system, or the register edited in Excel. You see what will change before anything is saved.</p>
    </div>
    <a href="{{ route('attendance.index', ['month' => $month]) }}" class="btn btn-outline-secondary">Attendance register</a>
</div>

<div class="row g-3">
    <div class="col-12 col-lg-6">
        <form method="POST" action="{{ route('attendance.import.preview') }}" enctype="multipart/form-data" class="erp-card p-3">
            @csrf
            @error('file')<div class="alert alert-danger small">{{ $message }}</div>@enderror
            @error('month')<div class="alert alert-danger small">{{ $message }}</div>@enderror
            <div class="mb-3">
                <label for="file" class="form-label">File (.csv or .xlsx)</label>
                <input type="file" id="file" name="file" class="form-control" accept=".csv,.txt,.xlsx,.xls,.ods" required>
            </div>
            <div class="mb-3">
                <label for="month" class="form-label">Month <span class="text-secondary small">(for the register layout only)</span></label>
                <input type="month" id="month" name="month" value="{{ old('month', $month) }}" class="form-control">
            </div>
            <button class="btn btn-primary">Preview</button>
        </form>
    </div>
    <div class="col-12 col-lg-6">
        <div class="erp-card p-3 small">
            <h2 class="h6 fw-bold">Two layouts</h2>
            <p class="mb-1"><strong>A list</strong>, one row per employee and day, with columns <code>Employee code</code>, <code>Date</code>, <code>Status</code> and optionally <code>Overtime hours</code>. Overtime on an employee's rows is added up for the month. Rows can cover more than one month.</p>
            <p class="mb-2">
                <a href="{{ route('attendance.import-template') }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-filetype-csv"></i> CSV template</a>
                <a href="{{ route('attendance.import-template', ['format' => 'xlsx']) }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-file-earmark-excel"></i> Excel template</a>
            </p>
            <p><strong>The register</strong> as the attendance register exports it: <code>Code</code>, a column for each day (1 to 31) and optionally <code>Overtime hours</code>, for the month chosen here. A blank cell leaves the day as it is.</p>
            <p class="mb-1"><strong>Status codes:</strong> P present · A absent · ½ or HD half day · W weekly off · H holiday
                @foreach ($leaveTypes as $t) · {{ $t->code }} {{ $t->name }}@endforeach
                · ½ before a leave code (e.g. ½{{ $leaveTypes->first()?->code ?? 'CL' }}) is half a day on leave. Words such as Present, Absent or Holiday also work.</p>
            <p class="mb-0 text-secondary">Days in the file replace what the register has for them; other days are kept. A file with any problem is not imported, so fix the rows listed and upload again.</p>
        </div>
    </div>
</div>
@endsection
