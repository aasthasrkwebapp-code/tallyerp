{{-- Financial statements: one screen, four views. Keeps the selected date. --}}
@php $date = isset($to) ? ['to' => $to->toDateString()] : []; @endphp
<ul class="nav nav-tabs mb-3 d-print-none">
    @foreach ([
        'companies.reports.profit-and-loss' => 'Profit & Loss',
        'companies.reports.balance-sheet' => 'Balance Sheet',
        'companies.reports.cash-flow' => 'Cash Flow',
        'companies.reports.ratios' => 'Ratio Analysis',
    ] as $route => $label)
        <li class="nav-item">
            <a class="nav-link {{ request()->routeIs($route) ? 'active' : '' }}" href="{{ route($route, [$company] + $date) }}">{{ $label }}</a>
        </li>
    @endforeach
</ul>
