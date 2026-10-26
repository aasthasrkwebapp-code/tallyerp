<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Dashboard') | {{ config('app.name') }}</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="{{ asset('css/erp.css') }}" rel="stylesheet">
    @stack('styles')
</head>
<body>
<a href="#main-content" class="visually-hidden-focusable position-absolute top-0 start-0 p-2 bg-white z-3">Skip to content</a>

<div class="erp-shell">
    @include('partials.sidebar')

    <div class="erp-main">
        <header class="erp-topbar px-3 py-2">
            <div class="d-flex align-items-center gap-2">
                <button class="btn btn-light d-lg-none" type="button" data-bs-toggle="offcanvas"
                        data-bs-target="#erpSidebar" aria-controls="erpSidebar" aria-label="Open menu">
                    <i class="bi bi-list fs-5"></i>
                </button>
                <button class="btn btn-light d-none d-lg-inline-flex" type="button" data-erp-collapse
                        aria-label="Collapse sidebar" aria-expanded="true">
                    <i class="bi bi-layout-sidebar"></i>
                </button>

                <div class="min-w-0 flex-grow-1">
                    <div class="fw-semibold text-truncate">@hasSection('page-heading') @yield('page-heading') @else @yield('title', 'Dashboard') @endif</div>
                    @isset($currentCompany)
                        <small class="text-secondary text-truncate d-block">
                            {{ $currentCompany->name }}
                            @isset($currentFinancialYear) · FY {{ $currentFinancialYear->name }} @endisset
                        </small>
                    @endisset
                </div>

                @isset($currentCompany)
                    @php($switchable = auth()->user()->companies()->wherePivot('is_active', true)->orderBy('name')->get(['companies.id', 'companies.name']))
                    @if ($switchable->count() > 1)
                        <div class="dropdown">
                            <button class="btn btn-outline-secondary btn-sm dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false">
                                <i class="bi bi-buildings"></i><span class="d-none d-md-inline ms-1">Switch</span>
                            </button>
                            <ul class="dropdown-menu dropdown-menu-end">
                                @foreach ($switchable as $c)
                                    <li>
                                        <form method="POST" action="{{ route('companies.switch', $c->id) }}">
                                            @csrf
                                            <button class="dropdown-item {{ $c->id === $currentCompany->id ? 'active' : '' }}">{{ $c->name }}</button>
                                        </form>
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endif
                @endisset

                <div class="dropdown">
                    <button class="btn btn-light btn-sm dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false">
                        <i class="bi bi-person-circle"></i>
                        <span class="d-none d-sm-inline ms-1">{{ auth()->user()->name }}</span>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li><a class="dropdown-item" href="{{ route('profile.edit') }}"><i class="bi bi-person me-2"></i>Profile</a></li>
                        <li><button class="dropdown-item" data-bs-toggle="modal" data-bs-target="#shortcutsModal"><i class="bi bi-keyboard me-2"></i>Keyboard shortcuts</button></li>
                        <li><hr class="dropdown-divider"></li>
                        <li>
                            <form method="POST" action="{{ route('logout') }}">
                                @csrf
                                <button type="submit" class="dropdown-item"><i class="bi bi-box-arrow-right me-2"></i>Log out</button>
                            </form>
                        </li>
                    </ul>
                </div>
            </div>
        </header>

        <main id="main-content" class="erp-content" tabindex="-1">
            @include('partials.flash')
            @yield('content')
        </main>
    </div>
</div>

<div class="modal fade" id="shortcutsModal" tabindex="-1" aria-labelledby="shortcutsTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title fs-5" id="shortcutsTitle">Keyboard shortcuts</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <table class="table table-sm mb-0">
                    <tbody>
                    <tr><td><kbd>Alt</kbd> + <kbd>D</kbd></td><td>Dashboard</td></tr>
                    <tr><td><kbd>Alt</kbd> + <kbd>V</kbd></td><td>New voucher</td></tr>
                    <tr><td><kbd>Alt</kbd> + <kbd>L</kbd></td><td>Ledgers</td></tr>
                    <tr><td><kbd>Alt</kbd> + <kbd>T</kbd></td><td>Trial balance</td></tr>
                    <tr><td><kbd>Alt</kbd> + <kbd>B</kbd></td><td>Day book</td></tr>
                    <tr><td><kbd>Alt</kbd> + <kbd>S</kbd></td><td>Save the current form</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="{{ asset('js/erp.js') }}"></script>
@stack('scripts')
</body>
</html>
