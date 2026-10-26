<aside class="erp-sidebar offcanvas-lg offcanvas-start" tabindex="-1" id="erpSidebar" aria-labelledby="erpSidebarLabel">
    <div class="d-flex align-items-center justify-content-between">
        <a href="{{ route('dashboard') }}" class="erp-brand text-decoration-none" id="erpSidebarLabel">
            <i class="bi bi-calculator-fill"></i><span class="brand-text">{{ config('app.name') }}</span>
        </a>
        <button type="button" class="btn-close btn-close-white d-lg-none me-3" data-bs-dismiss="offcanvas"
                data-bs-target="#erpSidebar" aria-label="Close menu"></button>
    </div>
    <nav class="nav flex-column pb-4" aria-label="Main navigation">
        @php($pendingHeading = null)
        @foreach (config('erp.menu') as $item)
            @if (isset($item['heading']))
                @php($pendingHeading = $item['heading'])
                @continue
            @endif
            @continue(! Route::has($item['route']))
            @continue(isset($item['can']) && (! isset($currentCompany) || auth()->user()->cannot($item['can'])))
            @if ($pendingHeading)
                <div class="nav-heading">{{ $pendingHeading }}</div>
                @php($pendingHeading = null)
            @endif
            @php($url = route($item['route'], $item['params'] ?? []))
            @php($pattern = str_ends_with($item['route'], '.index') ? Str::beforeLast($item['route'], '.').'.*' : $item['route'])
            @php($active = request()->routeIs($pattern)
                && collect($item['params'] ?? [])->every(fn ($v, $k) => request()->route($k) === $v || request($k) === $v))
            <a class="nav-link {{ $active ? 'active' : '' }}" href="{{ $url }}" title="{{ $item['label'] }}"
               @if ($active) aria-current="page" @endif>
                <i class="bi bi-{{ $item['icon'] }}" aria-hidden="true"></i><span class="nav-label">{{ $item['label'] }}</span>
            </a>
        @endforeach
    </nav>
</aside>
