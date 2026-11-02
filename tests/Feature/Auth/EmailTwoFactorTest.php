<?php

namespace Tests\Feature\Auth;

use App\Http\Controllers\Auth\TwoFactorController;
use App\Models\AuditLog;
use App\Models\User;
use App\Notifications\SignInCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class EmailTwoFactorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** The code in the newest e-mail sent to this user. */
    protected function lastCode(User $user): string
    {
        $sent = Notification::sent($user, SignInCode::class);
        $this->assertNotEmpty($sent, 'No code was e-mailed.');

        return $sent->last()->code;
    }

    protected function emailUser(): User
    {
        $user = User::factory()->create();
        $user->forceFill([
            'two_factor_method' => 'email',
            'two_factor_confirmed_at' => now(),
            'two_factor_recovery_codes' => [Hash::make('AAAAA11111')],
        ])->save();

        return $user->fresh();
    }

    public function test_set_up_by_email_code(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()]);

        $this->post(route('two-factor.email'))->assertRedirect(route('two-factor.show'));
        $this->assertTrue(session(TwoFactorController::PENDING_EMAIL_KEY));
        Notification::assertSentToTimes($user, SignInCode::class, 1);
        $this->assertTrue(Notification::sent($user, SignInCode::class)->last()->setup);
        $this->get(route('two-factor.show'))->assertOk()->assertSee('Type the 6-digit code we e-mailed');

        // Resend is held back for a minute.
        $this->post(route('two-factor.email'))->assertSessionHas('error');
        Notification::assertSentToTimes($user, SignInCode::class, 1);

        $code = $this->lastCode($user);
        $this->assertNotSame($code, $user->fresh()->two_factor_email_code); // stored hashed
        $wrong = $code === '000000' ? '111111' : '000000';
        $this->post(route('two-factor.email.confirm'), ['code' => $wrong])->assertSessionHasErrors('code');
        $this->assertFalse($user->fresh()->hasTwoFactor());

        $this->post(route('two-factor.email.confirm'), ['code' => $code])->assertRedirect(route('two-factor.show'))->assertSessionHas('two_factor.codes');
        $user->refresh();
        $this->assertTrue($user->hasTwoFactor());
        $this->assertTrue($user->usesEmailCodes());
        $this->assertNull($user->two_factor_secret);
        $this->assertSame(8, $user->recoveryCodesLeft());
        $this->assertTrue(AuditLog::where('event', 'two_factor_enabled')->exists());
        $this->get(route('two-factor.show'))->assertOk()->assertSee('codes e-mailed to '.$user->email);
    }

    public function test_setup_code_expires_and_confirm_needs_a_started_setup(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()]);
        $this->post(route('two-factor.email.confirm'), ['code' => '123456'])->assertStatus(409);

        $this->post(route('two-factor.email'));
        $code = $this->lastCode($user);
        Carbon::setTestNow(now()->addMinutes(User::EMAIL_CODE_MINUTES + 1));
        $this->post(route('two-factor.email.confirm'), ['code' => $code])->assertSessionHasErrors('code');
        $this->assertFalse($user->fresh()->hasTwoFactor());
    }

    public function test_sign_in_emails_a_code_that_works_once(): void
    {
        $user = $this->emailUser();

        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect(route('two-factor.login'));
        $this->get(route('two-factor.login'))->assertOk()->assertSee('We e-mailed a 6-digit code')->assertSee('Send a new code');
        Notification::assertSentToTimes($user, SignInCode::class, 1);
        $this->assertFalse(Notification::sent($user, SignInCode::class)->last()->setup);

        // Reloading the page does not send another while the code is valid.
        $this->get(route('two-factor.login'))->assertOk();
        Notification::assertSentToTimes($user, SignInCode::class, 1);

        $code = $this->lastCode($user);
        $wrong = $code === '000000' ? '111111' : '000000';
        $this->post(route('two-factor.login'), ['code' => $wrong])->assertSessionHasErrors('code');
        $this->assertGuest();
        $this->post(route('two-factor.login'), ['code' => $code])->assertRedirect(route('dashboard', absolute: false));
        $this->assertAuthenticatedAs($user);

        // The same code cannot be used again.
        $this->post('/logout');
        $this->post('/login', ['email' => $user->email, 'password' => 'password']);
        Carbon::setTestNow(now()->addSeconds(User::EMAIL_CODE_RESEND_SECONDS + 1));
        $this->post(route('two-factor.login'), ['code' => $code])->assertSessionHasErrors('code');
        $this->assertGuest();
    }

    public function test_resend_waits_a_minute_and_only_the_newest_code_works(): void
    {
        $user = $this->emailUser();
        $this->post('/login', ['email' => $user->email, 'password' => 'password']);
        $this->get(route('two-factor.login'));
        $first = $this->lastCode($user);

        $this->from(route('two-factor.login'))->post(route('two-factor.resend'))->assertSessionHasErrors('code');
        Notification::assertSentToTimes($user, SignInCode::class, 1);

        Carbon::setTestNow(now()->addSeconds(User::EMAIL_CODE_RESEND_SECONDS + 1));
        $this->from(route('two-factor.login'))->post(route('two-factor.resend'))->assertSessionHas('status');
        Notification::assertSentToTimes($user, SignInCode::class, 2);
        $second = $this->lastCode($user);

        if ($first !== $second) {
            $this->post(route('two-factor.login'), ['code' => $first])->assertSessionHasErrors('code');
            $this->assertGuest();
        }
        $this->post(route('two-factor.login'), ['code' => $second]);
        $this->assertAuthenticatedAs($user);
    }

    public function test_expired_code_is_refused_and_a_new_one_sent_on_reload(): void
    {
        $user = $this->emailUser();
        $this->post('/login', ['email' => $user->email, 'password' => 'password']);
        $this->get(route('two-factor.login'));
        $code = $this->lastCode($user);

        Carbon::setTestNow(now()->addMinutes(User::EMAIL_CODE_MINUTES)->addSecond());
        // Sign in again (the challenge itself lasts five minutes).
        $this->post('/login', ['email' => $user->email, 'password' => 'password']);
        $this->post(route('two-factor.login'), ['code' => $code])->assertSessionHasErrors('code');
        $this->assertGuest();

        $this->get(route('two-factor.login'))->assertOk();
        Notification::assertSentToTimes($user, SignInCode::class, 2);
    }

    public function test_recovery_code_and_turning_off(): void
    {
        $user = $this->emailUser();
        $this->post('/login', ['email' => $user->email, 'password' => 'password']);
        $this->get(route('two-factor.login'))->assertSee('No access to your e-mail?');
        $this->post(route('two-factor.login'), ['recovery_code' => 'AAAAA-11111']);
        $this->assertAuthenticatedAs($user);

        $this->delete(route('two-factor.destroy'), ['password' => 'password'])->assertSessionHas('success');
        $user->refresh();
        $this->assertFalse($user->hasTwoFactor());
        $this->assertFalse($user->usesEmailCodes());
        $this->assertNull($user->two_factor_email_code);
    }

    public function test_company_requirement_accepts_email_codes_and_owner_reset_clears_them(): void
    {
        [$owner, $company] = $this->createCompanyWithOwner();
        $member = $this->emailUser();
        $this->addMember($company, 'accountant', $member);

        $owner->forceFill(['two_factor_method' => 'email', 'two_factor_confirmed_at' => now()])->save();
        $this->actingAs($owner)->put(route('settings.preferences'), ['require_two_factor' => '1'])->assertSessionMissing('error');
        $this->actingAs($member)->get(route('dashboard'))->assertOk();

        $this->actingAs($owner)->post(route('members.reset-two-factor', $member), ['password' => 'password'])->assertSessionHas('success');
        $this->assertFalse($member->fresh()->hasTwoFactor());
        $this->assertSame('app', $member->fresh()->two_factor_method);
        $this->actingAs($member->fresh())->get(route('dashboard'))->assertRedirect(route('two-factor.show'));
    }
}
