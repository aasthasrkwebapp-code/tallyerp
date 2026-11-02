<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Support\Totp;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Setting up and switching off two-factor sign-in on the user's profile.
 */
class TwoFactorController extends Controller
{
    public const PENDING_KEY = 'two_factor.pending_secret';

    /** Set while a code e-mailed to turn on e-mail codes waits to be typed in. */
    public const PENDING_EMAIL_KEY = 'two_factor.pending_email';

    public function show(Request $request)
    {
        $user = $request->user();
        $pending = $user->hasTwoFactor() ? null : $request->session()->get(self::PENDING_KEY);
        $pendingEmail = ! $user->hasTwoFactor() && $request->session()->get(self::PENDING_EMAIL_KEY);
        $uri = $pending ? Totp::uri($pending, $user->email, config('app.name')) : null;

        return view('profile.two-factor', [
            'user' => $user,
            'pending' => $pendingEmail ? null : $pending,
            'pendingEmail' => $pendingEmail,
            'qr' => $uri ? Totp::qrSvg($uri) : null,
            'codes' => session('two_factor.codes'),
        ]);
    }

    /** Make a secret to scan; it is kept in the session until a code confirms it. */
    public function start(Request $request)
    {
        abort_if($request->user()->hasTwoFactor(), 409, 'Two-factor sign-in is already on.');
        $request->session()->forget(self::PENDING_EMAIL_KEY);
        $request->session()->put(self::PENDING_KEY, Totp::generateSecret());

        return redirect()->route('two-factor.show');
    }

    /** E-mail a code that, typed back, turns on e-mail sign-in codes. Also resends it. */
    public function startEmail(Request $request)
    {
        $user = $request->user();
        abort_if($user->hasTwoFactor(), 409, 'Two-factor sign-in is already on.');
        $request->session()->forget(self::PENDING_KEY);
        $request->session()->put(self::PENDING_EMAIL_KEY, true);
        if ($wait = $user->emailCodeWait()) {
            return redirect()->route('two-factor.show')->with('error', "A code was just sent. Use it, or ask for another in {$wait} seconds.");
        }
        $user->sendEmailCode(setup: true);

        return redirect()->route('two-factor.show')->with('success', "We e-mailed a 6-digit code to {$user->email}.");
    }

    public function confirmEmail(Request $request)
    {
        $user = $request->user();
        abort_if($user->hasTwoFactor() || ! $request->session()->get(self::PENDING_EMAIL_KEY), 409, 'Start the set-up again.');
        $data = $request->validate(['code' => ['required', 'string', 'max:10']]);
        if (! $user->useEmailCode($data['code'])) {
            return back()->withErrors(['code' => 'That code is not right or has expired. Ask for a new one if needed.']);
        }

        $codes = $this->newCodes();
        $user->forceFill([
            'two_factor_method' => 'email',
            'two_factor_secret' => null,
            'two_factor_confirmed_at' => now(),
            'two_factor_recovery_codes' => array_map(fn ($c) => Hash::make(str_replace('-', '', $c)), $codes),
        ])->save();
        $request->session()->forget(self::PENDING_EMAIL_KEY);
        AuditLog::record('two_factor_enabled', $user, null, null, 'Two-factor sign-in by e-mail code turned on: '.$user->email);

        return redirect()->route('two-factor.show')->with('two_factor.codes', $codes)
            ->with('success', 'Two-factor sign-in by e-mail code is on. Save your recovery codes now; they are shown only once.');
    }

    public function confirm(Request $request)
    {
        $user = $request->user();
        $secret = $request->session()->get(self::PENDING_KEY);
        abort_if($user->hasTwoFactor() || ! $secret, 409, 'Start the set-up again.');
        $data = $request->validate(['code' => ['required', 'string', 'max:10']]);

        $step = Totp::verify($secret, $data['code']);
        if ($step === null) {
            return back()->withErrors(['code' => 'That code is not right. Check the time on your phone and try the newest code.']);
        }

        $codes = $this->newCodes();
        $user->forceFill([
            'two_factor_method' => 'app',
            'two_factor_secret' => $secret,
            'two_factor_confirmed_at' => now(),
            'two_factor_last_step' => $step,
            'two_factor_recovery_codes' => array_map(fn ($c) => Hash::make(str_replace('-', '', $c)), $codes),
        ])->save();
        $request->session()->forget(self::PENDING_KEY);
        AuditLog::record('two_factor_enabled', $user, null, null, 'Two-factor sign-in turned on: '.$user->email);

        return redirect()->route('two-factor.show')->with('two_factor.codes', $codes)
            ->with('success', 'Two-factor sign-in is on. Save your recovery codes now; they are shown only once.');
    }

    public function regenerate(Request $request)
    {
        $user = $request->user();
        abort_unless($user->hasTwoFactor(), 409);
        $codes = $this->newCodes();
        $user->forceFill(['two_factor_recovery_codes' => array_map(fn ($c) => Hash::make(str_replace('-', '', $c)), $codes)])->save();
        AuditLog::record('two_factor_codes', $user, null, null, 'Recovery codes replaced: '.$user->email);

        return redirect()->route('two-factor.show')->with('two_factor.codes', $codes)
            ->with('success', 'New recovery codes made. The old ones no longer work.');
    }

    public function destroy(Request $request)
    {
        $request->validate(['password' => ['required', 'current_password']], ['password.current_password' => 'The password is incorrect.']);
        $user = $request->user();
        $user->forceFill($user->twoFactorOff())->save();
        $request->session()->forget([self::PENDING_KEY, self::PENDING_EMAIL_KEY]);
        AuditLog::record('two_factor_disabled', $user, null, null, 'Two-factor sign-in turned off: '.$user->email);

        return redirect()->route('two-factor.show')->with('success', 'Two-factor sign-in is off.');
    }

    /** @return array<int, string> eight codes like ABCDE-12345 */
    protected function newCodes(): array
    {
        return array_map(fn () => Str::upper(Str::random(5)).'-'.Str::upper(Str::random(5)), range(1, 8));
    }
}
