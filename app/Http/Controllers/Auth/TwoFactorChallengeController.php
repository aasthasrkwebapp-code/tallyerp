<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * Second step of signing in for users with two-factor sign-in on.
 * The password step leaves the user id in the session for five minutes.
 */
class TwoFactorChallengeController extends Controller
{
    public const SESSION_KEY = 'login.two_factor';
    public const TTL_SECONDS = 300;

    public function create(Request $request)
    {
        if (! $this->pendingUser($request)) {
            return redirect()->route('login')->withErrors(['email' => 'Please sign in again.']);
        }

        return view('auth.two-factor-challenge');
    }

    public function store(Request $request)
    {
        $user = $this->pendingUser($request);
        if (! $user) {
            return redirect()->route('login')->withErrors(['email' => 'Please sign in again.']);
        }
        $data = $request->validate([
            'code' => ['nullable', 'string', 'max:10'],
            'recovery_code' => ['nullable', 'string', 'max:20'],
        ]);

        $key = 'two-factor:'.$user->id.'|'.$request->ip();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages(['code' => 'Too many attempts. Try again in '.RateLimiter::availableIn($key).' seconds.']);
        }

        $usedRecovery = filled($data['recovery_code'] ?? null);
        $ok = $usedRecovery ? $user->useRecoveryCode($data['recovery_code']) : $user->verifyTwoFactorCode((string) ($data['code'] ?? ''));
        if (! $ok) {
            RateLimiter::hit($key);
            AuditLog::record('two_factor_failed', null, null, null, 'Wrong two-factor code for: '.$user->email);

            return back()->withErrors([$usedRecovery ? 'recovery_code' : 'code' => $usedRecovery ? 'That recovery code is not valid.' : 'That code is not right.']);
        }

        RateLimiter::clear($key);
        $remember = (bool) ($request->session()->get(self::SESSION_KEY)['remember'] ?? false);
        $request->session()->forget(self::SESSION_KEY);
        Auth::login($user, $remember);
        $request->session()->regenerate();

        $redirect = redirect()->intended(route('dashboard', absolute: false));
        if ($usedRecovery) {
            AuditLog::record('two_factor_recovery_used', $user, null, null, 'Signed in with a recovery code: '.$user->email.' ('.$user->recoveryCodesLeft().' left)');
            $redirect->with('warning', 'You signed in with a recovery code. '.$user->recoveryCodesLeft().' left. Make new codes from Profile → Two-factor sign-in.');
        }

        return $redirect;
    }

    protected function pendingUser(Request $request): ?User
    {
        $pending = $request->session()->get(self::SESSION_KEY);
        if (! $pending || now()->getTimestamp() - ($pending['at'] ?? 0) > self::TTL_SECONDS) {
            $request->session()->forget(self::SESSION_KEY);

            return null;
        }
        $user = User::find($pending['id']);

        return $user?->hasTwoFactor() ? $user : null;
    }
}
