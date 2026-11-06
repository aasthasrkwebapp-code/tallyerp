<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ $voucher->typeLabel() }} {{ $voucher->number }}/{{ $voucher->financialYearLabel() }} · {{ $company->legal_name ?: $company->name }}</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background: #f5f7fb; }
        @media print { body { background: #fff; } .no-print { display: none !important; } }
    </style>
</head>
<body>
    <div class="container py-4" style="max-width: 960px">
        <div class="d-flex justify-content-end gap-2 mb-3 no-print">
            <button type="button" class="btn btn-outline-secondary btn-sm" onclick="window.print()">Print / Save as PDF</button>
        </div>
        @include('gst-invoices._sheet')
    </div>
</body>
</html>
