<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? 'Sign in' }} | {{ config('app.name') }}</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="{{ asset('css/erp.css') }}" rel="stylesheet">
</head>
<body class="d-flex align-items-center min-vh-100 py-4">
<main class="container" style="max-width: 440px;">
    <div class="text-center mb-4">
        <a href="/" class="text-decoration-none d-inline-flex align-items-center gap-2 fs-4 fw-bold text-dark">
            <i class="bi bi-calculator-fill" style="color: var(--erp-accent)"></i>{{ config('app.name') }}
        </a>
        <div class="text-secondary small">Accounting &amp; business management</div>
    </div>
    <div class="erp-card p-4 shadow-sm">
        {{ $slot }}
    </div>
</main>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
