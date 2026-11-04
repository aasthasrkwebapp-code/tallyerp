{{-- Expects $title, $company; optional $subtitle. --}}
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
    <div>
        <h2 class="h4 mb-1">{{ $title }}</h2>
        <p class="text-secondary mb-0">{{ $company->name }}@isset($subtitle) · {{ $subtitle }}@endisset</p>
    </div>
    <div class="d-flex flex-wrap gap-2 d-print-none">
        <button type="button" class="btn btn-outline-secondary" onclick="window.print()">Print</button>
        <button type="button" class="btn btn-outline-secondary js-export-csv"
            data-filename="{{ \Illuminate\Support\Str::slug($company->name.' '.$title.' '.($subtitle ?? '')) }}.csv">
            Export to Excel
        </button>
        <a href="{{ route('companies.reports.index', $company) }}" class="btn btn-outline-secondary">All Reports</a>
    </div>
</div>

@once
<script>
// Download every table on the report as one CSV file (opens in Excel).
document.addEventListener('click', (event) => {
    const button = event.target.closest('.js-export-csv');
    if (!button) return;

    const clean = (cell) => {
        const field = cell.querySelector('input, select');
        let text = (field ? (field.tagName === 'SELECT' ? field.options[field.selectedIndex]?.text ?? '' : field.value) : cell.innerText)
            .replace(/\s+/g, ' ').trim();
        // "1,15,000.00" → 115000.00 so Excel treats it as a number; keep any Dr/Cr suffix.
        const number = text.match(/^(-?)₹?\s?([\d,]+(?:\.\d+)?)(\s(?:Dr|Cr))?$/);
        if (number) text = number[1] + number[2].replace(/,/g, '') + (number[3] ?? '');
        return '"' + text.replace(/"/g, '""') + '"';
    };

    const lines = [];
    document.querySelectorAll('main table').forEach((table, i) => {
        if (i > 0) lines.push('');
        const heading = table.closest('.card')?.querySelector('.card-header')?.innerText.trim();
        if (heading) lines.push('"' + heading.replace(/"/g, '""') + '"');
        table.querySelectorAll('tr').forEach((row) => {
            const cells = [...row.children].filter((c) => !c.classList.contains('d-print-none'));
            if (cells.length) lines.push(cells.map(clean).join(','));
        });
    });

    const blob = new Blob(['﻿' + lines.join('\r\n')], { type: 'text/csv;charset=utf-8' });
    const link = Object.assign(document.createElement('a'), { href: URL.createObjectURL(blob), download: button.dataset.filename });
    document.body.appendChild(link);
    link.click();
    link.remove();
    URL.revokeObjectURL(link.href);
});
</script>
@endonce
