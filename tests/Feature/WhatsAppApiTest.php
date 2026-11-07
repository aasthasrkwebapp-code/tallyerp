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

class WhatsAppApiTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected Account $party;

    protected Voucher $voucher;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = User::factory()->create()->companies()->create([
            'name' => 'WA Co', 'financial_year' => '2026-27', 'currency' => 'INR',
        ]);
        $debtors = $this->company->accountGroups()->where('code', 'SUNDRY_DEBTORS')->firstOrFail();
        $this->party = $this->company->accounts()->create([
            'name' => 'Ravi Traders', 'account_group_id' => $debtors->id, 'account_type' => $debtors->nature, 'phone' => '98765 43210',
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
            ->post(route('companies.gst-invoices.send', [$this->company, $this->voucher, 'whatsapp']))
            ->assertSessionHas('success', 'WhatsApp test mode (dummy keys): message to +919876543210 written to the log, not sent.');

        Http::assertNothingSent();
        Log::shouldHaveReceived('info')->withArgs(fn ($msg, $ctx) => $ctx['to'] === '919876543210' && str_contains($ctx['text'], 'Ravi Traders'));
    }

    public function test_real_keys_call_the_cloud_api(): void
    {
        config(['services.whatsapp.driver' => 'cloud', 'services.whatsapp.token' => 'EAAG-real', 'services.whatsapp.phone_number_id' => '123456']);
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.X']]])]);

        $this->actingAs($this->company->user)
            ->post(route('companies.reminders.send', [$this->company, $this->party, 'whatsapp']), ['amount' => 1180])
            ->assertSessionHas('success', 'WhatsApp message sent to +919876543210.');

        Http::assertSent(fn ($r) => $r->url() === 'https://graph.facebook.com/v21.0/123456/messages'
            && $r->hasHeader('Authorization', 'Bearer EAAG-real')
            && $r['to'] === '919876543210' && str_contains($r['text']['body'], '1,180.00'));
    }

    public function test_api_error_and_missing_phone_are_reported(): void
    {
        config(['services.whatsapp.driver' => 'cloud', 'services.whatsapp.token' => 'EAAG-real']);
        Http::fake(['*' => Http::response(['error' => ['message' => 'Invalid OAuth access token']], 401)]);

        $this->actingAs($this->company->user)
            ->post(route('companies.gst-invoices.send', [$this->company, $this->voucher, 'whatsapp']))
            ->assertSessionHas('error', 'WhatsApp error: Invalid OAuth access token');

        $this->party->update(['phone' => null]);
        $this->actingAs($this->company->user)
            ->post(route('companies.gst-invoices.send', [$this->company, $this->voucher, 'whatsapp']))
            ->assertSessionHas('error');
    }

    public function test_test_mode_uses_the_test_number_when_party_has_none(): void
    {
        $this->party->update(['phone' => null]);

        $this->actingAs($this->company->user)
            ->post(route('companies.gst-invoices.send', [$this->company, $this->voucher, 'whatsapp']))
            ->assertSessionHas('success', 'WhatsApp test mode (dummy keys): message to +919660533307 written to the log, not sent.');
    }

    public function test_other_users_are_blocked(): void
    {
        $this->actingAs(User::factory()->create())
            ->post(route('companies.gst-invoices.send', [$this->company, $this->voucher, 'whatsapp']))
            ->assertForbidden();
    }
}
