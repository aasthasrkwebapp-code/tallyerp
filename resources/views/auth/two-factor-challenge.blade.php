<x-guest-layout>
    <h1 class="h5 fw-bold mb-2">Two-factor sign-in</h1>

    <div id="tf-app">
        <p class="text-secondary small">Open your authenticator app and type the 6-digit code for {{ config('app.name') }}.</p>
        <form method="POST" action="{{ route('two-factor.login') }}" novalidate>
            @csrf
            <div class="mb-3">
                <label for="code" class="form-label">Code</label>
                <input id="code" name="code" class="form-control form-control-lg font-monospace @error('code') is-invalid @enderror" inputmode="numeric" autocomplete="one-time-code" maxlength="10" autofocus>
                @error('code')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <button type="submit" class="btn btn-primary w-100">Sign in</button>
        </form>
    </div>

    <details class="mt-3 small" @if ($errors->has('recovery_code')) open @endif>
        <summary>Lost your phone? Use a recovery code</summary>
        <form method="POST" action="{{ route('two-factor.login') }}" class="mt-2" novalidate>
            @csrf
            <input name="recovery_code" class="form-control font-monospace mb-2 @error('recovery_code') is-invalid @enderror" placeholder="ABCDE-12345" aria-label="Recovery code" autocomplete="off" maxlength="20">
            @error('recovery_code')<div class="invalid-feedback d-block mb-2">{{ $message }}</div>@enderror
            <button class="btn btn-outline-primary w-100">Use recovery code</button>
        </form>
    </details>

    <div class="mt-3 small"><a href="{{ route('login') }}">Back to sign in</a></div>
</x-guest-layout>
