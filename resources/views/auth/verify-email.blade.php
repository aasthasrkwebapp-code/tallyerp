<x-guest-layout>
    <h1 class="h5 fw-bold mb-2">Verify your email</h1>
    <p class="text-secondary small">
        Thanks for signing up! Please verify your email address by clicking the link we just emailed to you.
        If you did not receive the email, we will gladly send you another.
    </p>

    @if (session('status') == 'verification-link-sent')
        <div class="alert alert-success py-2">A new verification link has been sent to your email address.</div>
    @endif

    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mt-3">
        <form method="POST" action="{{ route('verification.send') }}">
            @csrf
            <button type="submit" class="btn btn-primary">Resend verification email</button>
        </form>
        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit" class="btn btn-link">Log out</button>
        </form>
    </div>
</x-guest-layout>
