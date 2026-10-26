@extends('layouts.erp')

@section('title', 'Profile')

@section('content')
<div class="page-header"><h1>Your profile</h1></div>

<div class="row g-3">
    <div class="col-12 col-xl-6">
        <section class="erp-card p-3 p-md-4 h-100">
            <h2 class="h6 fw-bold">Profile information</h2>
            <p class="text-secondary small">Update your name and email address.</p>

            <form id="send-verification" method="post" action="{{ route('verification.send') }}">@csrf</form>

            <form method="post" action="{{ route('profile.update') }}">
                @csrf
                @method('patch')
                <div class="mb-3">
                    <label for="name" class="form-label">Name</label>
                    <input id="name" name="name" type="text" class="form-control @error('name') is-invalid @enderror"
                           value="{{ old('name', $user->name) }}" required autocomplete="name">
                    @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="mb-3">
                    <label for="email" class="form-label">Email</label>
                    <input id="email" name="email" type="email" class="form-control @error('email') is-invalid @enderror"
                           value="{{ old('email', $user->email) }}" required autocomplete="username">
                    @error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror

                    @if ($user instanceof \Illuminate\Contracts\Auth\MustVerifyEmail && ! $user->hasVerifiedEmail())
                        <div class="small mt-2">
                            Your email address is unverified.
                            <button form="send-verification" class="btn btn-link btn-sm p-0 align-baseline">Re-send the verification email.</button>
                        </div>
                        @if (session('status') === 'verification-link-sent')
                            <div class="small text-success mt-1">A new verification link has been sent.</div>
                        @endif
                    @endif
                </div>
                <button class="btn btn-primary">Save</button>
                @if (session('status') === 'profile-updated')
                    <span class="text-success small ms-2">Saved.</span>
                @endif
            </form>
        </section>
    </div>

    <div class="col-12 col-xl-6">
        <section class="erp-card p-3 p-md-4 h-100">
            <h2 class="h6 fw-bold">Update password</h2>
            <p class="text-secondary small">Use a long, random password to stay secure.</p>
            <form method="post" action="{{ route('password.update') }}">
                @csrf
                @method('put')
                <div class="mb-3">
                    <label for="update_password_current_password" class="form-label">Current password</label>
                    <input id="update_password_current_password" name="current_password" type="password" autocomplete="current-password"
                           class="form-control @if ($errors->updatePassword->has('current_password')) is-invalid @endif">
                    <div class="invalid-feedback">{{ $errors->updatePassword->first('current_password') }}</div>
                </div>
                <div class="mb-3">
                    <label for="update_password_password" class="form-label">New password</label>
                    <input id="update_password_password" name="password" type="password" autocomplete="new-password"
                           class="form-control @if ($errors->updatePassword->has('password')) is-invalid @endif">
                    <div class="invalid-feedback">{{ $errors->updatePassword->first('password') }}</div>
                </div>
                <div class="mb-3">
                    <label for="update_password_password_confirmation" class="form-label">Confirm password</label>
                    <input id="update_password_password_confirmation" name="password_confirmation" type="password" autocomplete="new-password"
                           class="form-control @if ($errors->updatePassword->has('password_confirmation')) is-invalid @endif">
                    <div class="invalid-feedback">{{ $errors->updatePassword->first('password_confirmation') }}</div>
                </div>
                <button class="btn btn-primary">Update password</button>
                @if (session('status') === 'password-updated')
                    <span class="text-success small ms-2">Saved.</span>
                @endif
            </form>
        </section>
    </div>

    <div class="col-12">
        <section class="erp-card p-3 p-md-4 border-danger-subtle">
            <h2 class="h6 fw-bold text-danger">Delete account</h2>
            <p class="text-secondary small mb-3">
                Deleting your account removes your login permanently. Accounts that still own a company cannot be
                deleted, which protects the company's accounting records. Enter your password to confirm.
            </p>
            <form method="post" action="{{ route('profile.destroy') }}" class="row g-2 align-items-start"
                  data-confirm="Permanently delete your account?">
                @csrf
                @method('delete')
                <div class="col-12 col-sm-6 col-lg-4">
                    <label for="delete_password" class="visually-hidden">Password</label>
                    <input id="delete_password" name="password" type="password" placeholder="Password"
                           class="form-control @if ($errors->userDeletion->has('password')) is-invalid @endif">
                    <div class="invalid-feedback">{{ $errors->userDeletion->first('password') }}</div>
                </div>
                <div class="col-auto"><button class="btn btn-danger">Delete account</button></div>
            </form>
        </section>
    </div>
</div>
@endsection
