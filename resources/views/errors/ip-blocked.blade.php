<x-guest-layout>
    <h1 class="h5 fw-bold mb-2"><i class="bi bi-shield-lock text-danger"></i> Not from this address</h1>
    <p class="small mb-2">{{ $company->name }} can only be opened from the addresses its owner has allowed. You are connecting from <strong>{{ $ip }}</strong>.</p>
    <p class="small text-secondary">Connect from the office network or VPN, or ask the company's owner to add this address under Company settings → Security.</p>
    @if ($others->isNotEmpty())
        <div class="small fw-semibold mb-1">Open another company</div>
        <div class="d-grid gap-2 mb-3">
            @foreach ($others as $other)
                <form method="POST" action="{{ route('companies.switch', $other) }}">@csrf<button class="btn btn-outline-primary w-100">{{ $other->name }}</button></form>
            @endforeach
        </div>
    @endif
    <form method="POST" action="{{ route('logout') }}">@csrf<button class="btn btn-link p-0 small">Sign out</button></form>
</x-guest-layout>
