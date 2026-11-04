{{-- Expects $title, $company; optional $subtitle and $filters (rendered inside the GET form). --}}
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
    <div>
        <h2 class="h4 mb-1">{{ $title }}</h2>
        <p class="text-secondary mb-0">{{ $company->name }}@isset($subtitle) · {{ $subtitle }}@endisset</p>
    </div>
    <div class="d-flex flex-wrap gap-2 d-print-none">
        <button type="button" class="btn btn-outline-secondary" onclick="window.print()">Print</button>
        <a href="{{ route('companies.reports.index', $company) }}" class="btn btn-outline-secondary">All Reports</a>
    </div>
</div>
