@extends('layouts.erp')

@section('title', 'Two-factor sign-in')

@section('content')
<div class="page-header">
    <div>
        <h1>Two-factor sign-in
            @if ($user->hasTwoFactor())<span class="badge text-bg-success">On</span>@else<span class="badge text-bg-secondary">Off</span>@endif
        </h1>
        <p class="text-secondary mb-0">After your password, you type a 6-digit code from an authenticator app on your phone.</p>
    </div>
    <a href="{{ route('profile.edit') }}" class="btn btn-outline-secondary">Back to profile</a>
</div>

@if ($codes)
    <div class="erp-card p-3 p-md-4 mb-3 border-warning">
        <h2 class="h6 fw-bold">Your recovery codes</h2>
        <p class="small text-secondary">If you lose your phone, sign in with one of these codes instead. Each one works once. Store them somewhere safe, such as a password manager. They will not be shown again.</p>
        <div class="row g-2 font-monospace mb-3" style="max-width: 420px">
            @foreach ($codes as $c)<div class="col-6"><span class="d-block border rounded px-2 py-1 bg-light text-center">{{ $c }}</span></div>@endforeach
        </div>
        <button type="button" class="btn btn-sm btn-outline-secondary" onclick="navigator.clipboard && navigator.clipboard.writeText(@js(implode("\n", $codes))); this.textContent='Copied'"><i class="bi bi-clipboard me-1"></i>Copy codes</button>
    </div>
@endif

@if ($user->hasTwoFactor())
    <div class="row g-3">
        <div class="col-12 col-lg-6">
            <section class="erp-card p-3 p-md-4 h-100">
                <h2 class="h6 fw-bold">Recovery codes</h2>
                <p class="small text-secondary">{{ $user->recoveryCodesLeft() }} of 8 unused. Making new codes cancels the old ones.</p>
                <form method="POST" action="{{ route('two-factor.recovery-codes') }}" data-confirm="Make new recovery codes? The old ones will stop working.">
                    @csrf
                    <button class="btn btn-outline-primary">Make new recovery codes</button>
                </form>
            </section>
        </div>
        <div class="col-12 col-lg-6">
            <section class="erp-card p-3 p-md-4 h-100 border-danger-subtle">
                <h2 class="h6 fw-bold text-danger">Turn off two-factor sign-in</h2>
                <p class="small text-secondary">Turned on {{ $user->two_factor_confirmed_at?->format('d M Y') }}. If your company requires it, you will be asked to set it up again.</p>
                <form method="POST" action="{{ route('two-factor.destroy') }}" class="row g-2 align-items-end">
                    @csrf
                    @method('DELETE')
                    <div class="col-12 col-sm-7">
                        <label for="tf-password" class="form-label">Password</label>
                        <input id="tf-password" type="password" name="password" class="form-control @error('password') is-invalid @enderror" autocomplete="current-password" required>
                        @error('password')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-12 col-sm-5"><button class="btn btn-outline-danger w-100">Turn off</button></div>
                </form>
            </section>
        </div>
    </div>
@elseif ($pending)
    <section class="erp-card p-3 p-md-4" style="max-width: 720px">
        <h2 class="h6 fw-bold">1. Scan this QR code with your authenticator app</h2>
        <div class="d-flex flex-wrap gap-4 align-items-center mb-3">
            <div class="bg-white p-2 border rounded" style="width: 216px">{!! $qr !!}</div>
            <div class="small">
                <div class="text-secondary">Can't scan it? Enter this key instead:</div>
                <div class="font-monospace fs-6 text-break" id="tf-secret">{{ \App\Support\Totp::formatSecret($pending) }}</div>
                <div class="text-secondary mt-2">Account: {{ $user->email }} · Time-based, 6 digits</div>
            </div>
        </div>
        <h2 class="h6 fw-bold">2. Type the 6-digit code the app shows</h2>
        <form method="POST" action="{{ route('two-factor.confirm') }}" class="row g-2 align-items-start" style="max-width: 420px">
            @csrf
            <div class="col-7">
                <input name="code" class="form-control form-control-lg font-monospace @error('code') is-invalid @enderror" inputmode="numeric" autocomplete="one-time-code" maxlength="10" placeholder="123456" aria-label="Authenticator code" autofocus required>
                @error('code')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="col-5"><button class="btn btn-primary btn-lg w-100">Turn on</button></div>
        </form>
    </section>
@else
    <section class="erp-card p-3 p-md-4" style="max-width: 720px">
        <p>Two-factor sign-in stops anyone who learns your password from opening your books. You need an authenticator app on your phone.</p>
        <form method="POST" action="{{ route('two-factor.start') }}">
            @csrf
            <button class="btn btn-primary"><i class="bi bi-shield-lock me-1"></i>Set up two-factor sign-in</button>
        </form>
    </section>
@endif
@endsection
