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

    public function show(Request $request)
    {
        $user = $request->user();
        $pending = $user->hasTwoFactor() ? null : $request->session()->get(self::PENDING_KEY);
        $uri = $pending ? Totp::uri($pending, $user->email, config('app.name')) : null;

        return view('profile.two-factor', [
            'user' => $user,
            'pending' => $pending,
            'qr' => $uri ? Totp::qrSvg($uri) : null,
            'codes' => session('two_factor.codes'),
        ]);
    }

    /** Make a secret to scan; it is kept in the session until a code confirms it. */
    public function start(Request $request)
    {
        abort_if($request->user()->hasTwoFactor(), 409, 'Two-factor sign-in is already on.');
        $request->session()->put(self::PENDING_KEY, Totp::generateSecret());

        return redirect()->route('two-factor.show');
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
        $user->forceFill(['two_factor_secret' => null, 'two_factor_recovery_codes' => null, 'two_factor_confirmed_at' => null, 'two_factor_last_step' => null])->save();
        $request->session()->forget(self::PENDING_KEY);
        AuditLog::record('two_factor_disabled', $user, null, null, 'Two-factor sign-in turned off: '.$user->email);

        return redirect()->route('two-factor.show')->with('success', 'Two-factor sign-in is off.');
    }

    /** @return array<int, string> eight codes like ABCDE-12345 */
    protected function newCodes(): array
    {
        return array_map(fn () => Str::upper(Str::random(5)).'-'.Str::upper(Str::random(5)), range(1, 8));
    }
}
