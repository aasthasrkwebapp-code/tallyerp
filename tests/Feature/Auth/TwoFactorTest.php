<?php

namespace Tests\Feature\Auth;

use App\Http\Controllers\Auth\TwoFactorController;
use App\Models\AuditLog;
use App\Models\User;
use App\Support\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class TwoFactorTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** A user with two-factor on; returns [user, secret, recovery codes]. */
    protected function enabledUser(): array
    {
        $user = User::factory()->create();
        $secret = Totp::generateSecret();
        $codes = ['AAAAA-11111', 'BBBBB-22222'];
        $user->forceFill([
            'two_factor_secret' => $secret,
            'two_factor_confirmed_at' => now(),
            'two_factor_recovery_codes' => array_map(fn ($c) => Hash::make(str_replace('-', '', $c)), $codes),
        ])->save();

        return [$user->fresh(), $secret, $codes];
    }

    public function test_totp_matches_rfc_6238_vectors(): void
    {
        $secret = Totp::base32Encode('12345678901234567890');
        $this->assertSame('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', $secret);
        $this->assertSame('287082', Totp::code($secret, intdiv(59, 30)));
        $this->assertSame('081804', Totp::code($secret, intdiv(1111111109, 30)));
        $this->assertSame('353130', Totp::code($secret, intdiv(20000000000, 30)));
    }

    public function test_set_up_confirms_with_a_code_and_shows_recovery_codes_once(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()]);

        $this->get(route('two-factor.show'))->assertOk()->assertSee('Set up two-factor sign-in');
        $this->post(route('two-factor.start'))->assertRedirect(route('two-factor.show'));
        $secret = session(TwoFactorController::PENDING_KEY);
        $this->get(route('two-factor.show'))->assertOk()->assertSee('<svg', false)->assertSee(Totp::formatSecret($secret));

        $this->post(route('two-factor.confirm'), ['code' => '000000'])->assertSessionHasErrors('code');
        $this->assertFalse($user->fresh()->hasTwoFactor());

        $this->post(route('two-factor.confirm'), ['code' => Totp::code($secret)])->assertRedirect(route('two-factor.show'))->assertSessionHas('two_factor.codes');
        $user->refresh();
        $this->assertTrue($user->hasTwoFactor());
        $this->assertSame(8, $user->recoveryCodesLeft());
        $this->assertNotSame($secret, $user->getRawOriginal('two_factor_secret')); // stored encrypted
        $this->assertTrue(AuditLog::where('event', 'two_factor_enabled')->exists());
    }

    public function test_setup_page_needs_the_password_again(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->get(route('two-factor.show'))->assertRedirect(route('password.confirm'));
    }

    public function test_sign_in_asks_for_the_code_and_refuses_reuse(): void
    {
        [$user, $secret] = $this->enabledUser();

        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect(route('two-factor.login'));
        $this->assertGuest();
        $this->get(route('two-factor.login'))->assertOk()->assertSee('authenticator app');

        $this->post(route('two-factor.login'), ['code' => '123456' === Totp::code($secret) ? '654321' : '123456'])->assertSessionHasErrors('code');
        $this->assertGuest();
        $this->assertTrue(AuditLog::where('event', 'two_factor_failed')->exists());

        $code = Totp::code($secret);
        $this->post(route('two-factor.login'), ['code' => $code])->assertRedirect(route('dashboard', absolute: false));
        $this->assertAuthenticatedAs($user);
        $this->assertTrue(AuditLog::where('event', 'login')->exists());

        // The same code cannot be used for a second sign-in.
        auth()->logout();
        $this->post('/login', ['email' => $user->email, 'password' => 'password']);
        $this->post(route('two-factor.login'), ['code' => $code])->assertSessionHasErrors('code');
        $this->assertGuest();
    }

    public function test_recovery_code_works_once(): void
    {
        [$user, , $codes] = $this->enabledUser();

        $this->post('/login', ['email' => $user->email, 'password' => 'password']);
        $this->post(route('two-factor.login'), ['recovery_code' => strtolower($codes[0])])->assertRedirect()->assertSessionHas('warning');
        $this->assertAuthenticatedAs($user);
        $this->assertSame(1, $user->fresh()->recoveryCodesLeft());

        auth()->logout();
        $this->post('/login', ['email' => $user->email, 'password' => 'password']);
        $this->post(route('two-factor.login'), ['recovery_code' => $codes[0]])->assertSessionHasErrors('recovery_code');
        $this->assertGuest();
    }

    public function test_challenge_expires_and_is_rate_limited(): void
    {
        [$user] = $this->enabledUser();
        // Frozen clock: otherwise a slow run can cross a second and report "59 seconds".
        Carbon::setTestNow(now()->startOfSecond());
        $this->get(route('two-factor.login'))->assertRedirect(route('login'));

        $this->post('/login', ['email' => $user->email, 'password' => 'password']);
        for ($i = 0; $i < 5; $i++) {
            $this->post(route('two-factor.login'), ['code' => 'abcdef']);
        }
        $this->post(route('two-factor.login'), ['code' => '111111'])->assertSessionHasErrors(['code' => 'Too many attempts. Try again in 60 seconds.']);

        Carbon::setTestNow(now()->addMinutes(6));
        $this->get(route('two-factor.login'))->assertRedirect(route('login'));
    }

    public function test_wrong_password_never_reaches_the_challenge(): void
    {
        [$user] = $this->enabledUser();
        $this->post('/login', ['email' => $user->email, 'password' => 'wrong'])->assertSessionHasErrors('email');
        $this->get(route('two-factor.login'))->assertRedirect(route('login'));
        $this->assertTrue(AuditLog::where('event', 'login_failed')->exists());
    }

    public function test_turning_off_needs_the_password(): void
    {
        [$user] = $this->enabledUser();
        $this->actingAs($user)->delete(route('two-factor.destroy'), ['password' => 'wrong'])->assertSessionHasErrors('password');
        $this->assertTrue($user->fresh()->hasTwoFactor());
        $this->actingAs($user)->delete(route('two-factor.destroy'), ['password' => 'password'])->assertRedirect();
        $this->assertFalse($user->fresh()->hasTwoFactor());
    }

    public function test_company_can_require_two_factor(): void
    {
        [$owner, $company] = $this->createCompanyWithOwner();
        $member = $this->addMember($company, 'accountant');

        // The owner must have it first, so they are not locked out.
        $this->actingAs($owner)->put(route('settings.preferences'), ['require_two_factor' => '1'])->assertSessionHas('error');
        $this->assertFalse((bool) $company->fresh()->setting('require_two_factor'));

        $owner->forceFill(['two_factor_secret' => Totp::generateSecret(), 'two_factor_confirmed_at' => now()])->save();
        $this->actingAs($owner)->put(route('settings.preferences'), ['require_two_factor' => '1'])->assertSessionMissing('error');
        $this->assertTrue((bool) $company->fresh()->setting('require_two_factor'));

        $this->actingAs($member)->get(route('dashboard'))->assertRedirect(route('two-factor.show'));
        $this->actingAs($owner)->get(route('dashboard'))->assertOk();
    }

    public function test_owner_can_reset_a_members_two_factor(): void
    {
        [$owner, $company] = $this->createCompanyWithOwner();
        [$member] = $this->enabledUser();
        $this->addMember($company, 'accountant', $member);
        $admin = $this->addMember($company, 'accountant');

        $this->actingAs($owner)->get(route('members.index'))->assertOk()->assertSee('2FA');
        $this->actingAs($admin)->post(route('members.reset-two-factor', $member), ['password' => 'password'])->assertForbidden();
        $this->actingAs($owner)->post(route('members.reset-two-factor', $member), ['password' => 'wrong'])->assertSessionHasErrors('password');
        $this->assertTrue($member->fresh()->hasTwoFactor());

        // Not when the member also uses a company this owner does not manage.
        [, $other] = $this->createCompanyWithOwner(['name' => 'Other Co']);
        $this->addMember($other, 'viewer', $member);
        $this->useCompany($company);
        $this->actingAs($owner)->post(route('members.reset-two-factor', $member), ['password' => 'password'])->assertSessionHas('error');
        $this->assertTrue($member->fresh()->hasTwoFactor());

        $other->members()->detach($member->id);
        $this->actingAs($owner)->post(route('members.reset-two-factor', $member), ['password' => 'password'])->assertSessionHas('success');
        $this->assertFalse($member->fresh()->hasTwoFactor());
        $this->assertTrue(AuditLog::where('event', 'two_factor_reset')->exists());
    }
}
