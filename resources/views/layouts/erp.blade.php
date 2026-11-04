
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Dashboard') | Business ERP</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">

    <style>
        :root {
            --erp-sidebar: #172033;
            --erp-bg: #f5f7fb;
            --erp-accent: #4f46e5;
        }

        body {
            background: var(--erp-bg);
            color: #1f2937;
            font-family: Arial, sans-serif;
        }

        .erp-sidebar {
            width: 255px;
            min-height: 100vh;
            background: var(--erp-sidebar);
            color: white;
        }

        .erp-brand {
            font-size: 1.25rem;
            font-weight: 700;
            padding: 1.4rem 1.25rem;
            border-bottom: 1px solid #344054;
        }

        .erp-sidebar .nav-link {
            color: #cbd5e1;
            border-radius: 8px;
            padding: .75rem 1rem;
            margin: 3px 10px;
        }

        .erp-sidebar .nav-link:hover,
        .erp-sidebar .nav-link.active {
            background: #374151;
            color: white;
        }

        .erp-main {
            min-width: 0;
            flex: 1;
        }

        .erp-topbar {
            background: white;
            border-bottom: 1px solid #e5e7eb;
        }

        .erp-card {
            background: white;
            border: 1px solid #e8ebf1;
            border-radius: 14px;
            padding: 1.25rem;
            height: 100%;
        }

        .erp-icon {
            width: 44px;
            height: 44px;
            display: grid;
            place-items: center;
            border-radius: 12px;
            background: #eef2ff;
            color: var(--erp-accent);
            font-size: 1.25rem;
        }

        .erp-module {
            display: block;
            color: inherit;
            text-decoration: none;
            transition: transform .15s ease;
        }

        .erp-module:hover {
            transform: translateY(-2px);
            color: var(--erp-accent);
        }

        @media (max-width: 767.98px) {
            .erp-sidebar {
                width: 100%;
                min-height: auto;
            }

            .erp-sidebar .nav {
                display: grid;
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .erp-brand {
                padding: 1rem;
            }
        }
    </style>
</head>
<body>
<div class="d-md-flex min-vh-100">

    <aside class="erp-sidebar">
        <div class="erp-brand">
            <i class="bi bi-grid-1x2-fill me-2"></i>Business ERP
        </div>

        @php
            // [route, params, icon, label, active pattern]
            $nav = [['dashboard', [], 'speedometer2', 'Dashboard', 'dashboard'], ['companies.index', [], 'building', 'Companies', 'companies.index']];
            // Only inside a company's pages (the URL has {company}), not e.g. the companies list.
            $activeCompany = request()->route('company');
            $activeCompany = $activeCompany instanceof \App\Models\Company ? $activeCompany : null;
            if ($activeCompany) {
                $nav = array_merge($nav, [
                    ['companies.show', [$activeCompany], 'house', 'Company', 'companies.show'],
                    ['companies.vouchers.index', [$activeCompany], 'journal-text', 'Vouchers', 'companies.vouchers.*'],
                    ['companies.gst-invoices.create', [$activeCompany, 'type' => 'sales'], 'receipt', 'Sales Invoice', null],
                    ['companies.gst-invoices.create', [$activeCompany, 'type' => 'purchase'], 'cart3', 'Purchase Invoice', null],
                    ['companies.accounts.index', [$activeCompany], 'person-lines-fill', 'Ledgers', 'companies.accounts.*'],
                    ['companies.account-groups.index', [$activeCompany], 'diagram-3', 'Groups', 'companies.account-groups.*'],
                    ['companies.stock-items.index', [$activeCompany], 'box-seam', 'Inventory', 'companies.stock-items.*'],
                    ['companies.banking.reconciliation', [$activeCompany], 'bank', 'Banking', 'companies.banking.*'],
                    ['companies.payroll.index', [$activeCompany], 'people', 'Payroll', ['companies.payroll.*', 'companies.employees.*']],
                    ['companies.members.index', [$activeCompany], 'shield-lock', 'Users & Roles', 'companies.members.*'],
                    ['companies.audit', [$activeCompany], 'clock-history', 'Audit Log', 'companies.audit'],
                    ['companies.backup', [$activeCompany], 'cloud-download', 'Download Backup', null],
                    ['companies.reports.index', [$activeCompany], 'bar-chart-line', 'Reports', 'companies.reports.*'],
                ]);
            }
        @endphp
        <nav class="nav flex-column py-3">
            @foreach ($nav as [$routeName, $params, $icon, $label, $pattern])
                @continue($activeCompany && str_starts_with($routeName, 'companies.') && $routeName !== 'companies.index'
                    && ! $activeCompany->userCan(auth()->user(), \App\Support\Access::moduleForRoute($routeName), str_ends_with($routeName, '.create')))
                <a class="nav-link {{ $pattern && request()->routeIs($pattern) ? 'active' : '' }}"
                    href="{{ route($routeName, $params) }}">
                    <i class="bi bi-{{ $icon }} me-2"></i>{{ $label }}
                </a>
                @if ($loop->index === 1 && $activeCompany)
                    <div class="px-3 pt-3 pb-1 small text-uppercase opacity-50 text-truncate">{{ $activeCompany->name }}</div>
                @endif
            @endforeach
        </nav>
    </aside>

    <main class="erp-main">
        <header class="erp-topbar px-3 px-lg-4 py-3">
            <div class="d-flex justify-content-between align-items-center gap-3">
                <div>
                    <div class="fw-semibold">@yield('page-heading', 'Dashboard')</div>
                    <small class="text-secondary">Business management workspace</small>
                </div>

                <div class="d-flex align-items-center gap-3">
                    <span class="d-none d-sm-inline text-secondary">
                        {{ auth()->user()->name }}
                    </span>
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit" class="btn btn-outline-secondary btn-sm">
                            <i class="bi bi-box-arrow-right me-1"></i>Logout
                        </button>
                    </form>
                </div>
            </div>
        </header>

        <div class="p-3 p-lg-4">
            @yield('content')
        </div>
    </main>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
