<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Company;
use App\Models\User;
use App\Models\Voucher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class SmsTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected Account $party;

    protected Voucher $voucher;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = User::factory()->create()->companies()->create([
            'name' => 'SMS Co', 'financial_year' => '2026-27', 'currency' => 'INR',
        ]);
        $debtors = $this->company->accountGroups()->where('code', 'SUNDRY_DEBTORS')->firstOrFail();
        $this->party = $this->company->accounts()->create([
            'name' => 'Ravi Traders', 'account_group_id' => $debtors->id, 'account_type' => $debtors->nature, 'phone' => '9876543210',
        ]);
        $this->voucher = $this->company->vouchers()->create([
            'type' => 'sales', 'number' => 1, 'date' => '2026-06-10', 'amount' => 1180, 'party_account_id' => $this->party->id,
        ]);
    }

    public function test_dummy_keys_log_instead_of_sending(): void
    {
        Http::fake();
        Log::spy();

        $this->actingAs($this->company->user)
            ->post(route('companies.gst-invoices.sms', [$this->company, $this->voucher]))
            ->assertSessionHas('success', 'SMS test mode (dummy keys): message to +919876543210 written to the log, not sent.');

        Http::assertNothingSent();
        Log::shouldHaveReceived('info')->withArgs(fn ($msg, $ctx) => $msg === 'SMS (log mode, not sent)' && $ctx['to'] === '919876543210');
    }

    public function test_test_mode_uses_the_test_number_when_party_has_none(): void
    {
        $this->party->update(['phone' => null]);

        $this->actingAs($this->company->user)
            ->post(route('companies.sms.reminder', [$this->company, $this->party]), ['amount' => 500])
            ->assertSessionHas('success', 'SMS test mode (dummy keys): message to +919660533307 written to the log, not sent.');
    }

    public function test_real_keys_call_the_gateway(): void
    {
        config(['services.sms.driver' => 'http', 'services.sms.api_key' => 'live-key', 'services.sms.url' => 'https://api.sms.test/send']);
        Http::fake(['api.sms.test/*' => Http::response(['status' => 'ok'])]);

        $this->actingAs($this->company->user)
            ->post(route('companies.sms.reminder', [$this->company, $this->party]), ['amount' => 1180])
            ->assertSessionHas('success', 'SMS sent to +919876543210.');

        Http::assertSent(fn ($r) => $r->url() === 'https://api.sms.test/send'
            && $r->hasHeader('Authorization', 'Bearer live-key')
            && $r['to'] === '919876543210' && $r['sender'] === 'TLYERP' && str_contains($r['message'], '1,180.00'));
    }

    public function test_gateway_error_missing_phone_and_other_users(): void
    {
        config(['services.sms.driver' => 'http', 'services.sms.api_key' => 'live-key', 'services.sms.url' => 'https://api.sms.test/send']);
        Http::fake(['*' => Http::response(['message' => 'Invalid API key'], 401)]);

        $this->actingAs($this->company->user)
            ->post(route('companies.gst-invoices.sms', [$this->company, $this->voucher]))
            ->assertSessionHas('error', 'SMS gateway error: Invalid API key');

        $this->party->update(['phone' => null]);
        $this->actingAs($this->company->user)
            ->post(route('companies.gst-invoices.sms', [$this->company, $this->voucher]))
            ->assertSessionHas('error');

        $this->actingAs(User::factory()->create())
            ->post(route('companies.gst-invoices.sms', [$this->company, $this->voucher]))
            ->assertForbidden();
    }
}
