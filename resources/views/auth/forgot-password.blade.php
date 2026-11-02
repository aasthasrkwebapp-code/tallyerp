<x-guest-layout>
    <h1 class="h5 fw-bold mb-2">Reset your password</h1>
    <p class="text-secondary small">Enter your email address and we will send you a link to choose a new password.</p>

    @if (session('status'))
        <div class="alert alert-success py-2">{{ session('status') }}</div>
    @endif

    <form method="POST" action="{{ route('password.email') }}" novalidate>
        @csrf
        <div class="mb-3">
            <label for="email" class="form-label">Email</label>
            <input id="email" type="email" name="email" value="{{ old('email') }}" required autofocus
                   class="form-control @error('email') is-invalid @enderror">
            @error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <button type="submit" class="btn btn-primary w-100">Email password reset link</button>
        <div class="text-center mt-3 small"><a href="{{ route('login') }}">Back to sign in</a></div>
    </form>
</x-guest-layout>
