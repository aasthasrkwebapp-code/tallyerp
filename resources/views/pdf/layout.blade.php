<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>@yield('title')</title>
    <style>
        @page { margin: 12mm 10mm 14mm 10mm; }
        body { font-family: "DejaVu Sans", sans-serif; font-size: 9pt; color: #111; }
        h1 { font-size: 13pt; margin: 0 0 2pt 0; }
        .muted { color: #555; }
        .small { font-size: 8pt; }
        .right { text-align: right; }
        .center { text-align: center; }
        .bold { font-weight: bold; }
        .upper { text-transform: uppercase; }
        table { width: 100%; border-collapse: collapse; }
        table.grid th, table.grid td { border: 0.6pt solid #666; padding: 2.5pt 4pt; vertical-align: top; }
        table.grid th { background: #eef0f3; text-align: left; }
        table.lines td { padding: 2pt 4pt; border-bottom: 0.4pt solid #ccc; }
        table.lines thead td, table.lines th { border-bottom: 0.8pt solid #333; font-weight: bold; padding: 3pt 4pt; text-align: left; }
        td.num, th.num { text-align: right; white-space: nowrap; }
        tr.total td { font-weight: bold; border-top: 0.8pt solid #333; }
        .pre { white-space: pre-line; }
        .stamp { color: #b91c1c; border: 1.5pt solid #b91c1c; padding: 1pt 5pt; font-weight: bold; display: inline-block; }
        .footer { position: fixed; bottom: -9mm; left: 0; right: 0; font-size: 7pt; color: #777; }
        .page-number:after { content: counter(page); }
    </style>
</head>
<body>
<div class="footer"><table><tr><td>{{ $footerLeft ?? '' }}</td><td class="right">Page <span class="page-number"></span> · printed {{ now()->format('d-m-Y H:i') }}</td></tr></table></div>
@yield('content')
</body>
</html>
