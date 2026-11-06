<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use App\Support\WhatsApp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WhatsAppTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = User::factory()->create()->companies()->create([
            'name' => 'Acme Traders', 'gstin' => '27AAAAA0000A1Z5', 'financial_year' => '2026-27', 'currency' => 'INR',
            'upi_id' => 'acme@okhdfcbank',
        ]);
    }

    protected function sale(?string $phone = '98765 43210'): \App\Models\Voucher
    {
        $group = fn (string $code) => $this->company->accountGroups()->where('code', $code)->value('id');
        $sales = $this->company->accounts()->create(['name' => 'Sales', 'account_group_id' => $group('SALES_ACCOUNTS'), 'account_type' => 'income']);
        $customer = $this->company->accounts()->create([
            'name' => 'Pune Stores', 'account_group_id' => $group('SUNDRY_DEBTORS'), 'account_type' => 'assets', 'state_code' => '27', 'phone' => $phone,
        ]);

        $this->actingAs($this->company->user)
            ->post(route('companies.gst-invoices.store', $this->company), [
                'type' => 'sales', 'date' => '2026-07-01', 'party_account_id' => $customer->id,
                'lines' => [['account_id' => $sales->id, 'taxable' => 10000, 'rate' => 18, 'hsn_sac' => '8471']],
            ])
            ->assertSessionHasNoErrors();

        return $this->company->vouchers()->firstOrFail();
    }

    public function test_phone_numbers_are_normalised(): void
    {
        $this->assertSame('919876543210', WhatsApp::number('98765 43210'));
        $this->assertSame('919876543210', WhatsApp::number('09876543210'));
        $this->assertSame('919876543210', WhatsApp::number('+91 98765-43210'));
        $this->assertNull(WhatsApp::number('12345'));
        $this->assertStringStartsWith('https://wa.me/?text=', WhatsApp::link(null, 'Hi'));
    }

    public function test_invoice_page_has_whatsapp_message_with_signed_link(): void
    {
        $sale = $this->sale();

        $response = $this->actingAs($this->company->user)
            ->get(route('companies.gst-invoices.show', [$this->company, $sale]))
            ->assertOk()
            ->assertSee('Send on WhatsApp')
            ->assertSee('https://wa.me/919876543210?text=', false);

        $message = WhatsApp::invoiceMessage($sale->fresh(['company', 'party']));
        $this->assertStringContainsString('Sales No. 1/2026-27 dated 01-07-2026 for ₹ 11,800.00.', $message);
        $this->assertStringContainsString('Pay by UPI: acme@okhdfcbank', $message);
        $this->assertStringContainsString('signature=', $message);
    }

    public function test_signed_link_opens_without_login_and_rejects_tampering(): void
    {
        $sale = $this->sale();
        $url = WhatsApp::invoiceUrl($sale);

        auth()->logout();
        $this->get($url)->assertOk()->assertSee('TAX INVOICE')->assertSee('11,800.00')->assertDontSee('Logout');

        // Changing the invoice number in the link is refused (403 bad signature / 404 no such invoice).
        $tampered = $this->get(str_replace('/i/'.$sale->id, '/i/'.($sale->id + 1), $url));
        $this->assertContains($tampered->status(), [403, 404]);
        $this->get(route('invoices.public', $sale))->assertForbidden(); // unsigned
    }

    public function test_receivables_have_reminder_buttons(): void
    {
        $this->sale();

        $this->actingAs($this->company->user)
            ->get(route('companies.outstanding', [$this->company, 'receivables', 'to' => '2026-07-31']))
            ->assertOk()
            ->assertSee('Remind')
            ->assertSee('https://wa.me/919876543210?text=Dear%20Pune%20Stores', false)
            ->assertSee(rawurlencode('₹ 11,800.00 is due to Acme Traders'), false);
    }

    public function test_ledger_saves_phone_and_email(): void
    {
        $this->actingAs($this->company->user)
            ->post(route('companies.accounts.store', $this->company), [
                'name' => 'New Customer', 'account_group_id' => $this->company->accountGroups()->where('code', 'SUNDRY_DEBTORS')->value('id'),
                'opening_balance_type' => 'dr', 'is_active' => '1', 'phone' => '+91 98200 12345', 'email' => 'buyer@example.com',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('+91 98200 12345', $this->company->accounts()->where('name', 'New Customer')->value('phone'));

        $this->actingAs($this->company->user)
            ->post(route('companies.accounts.store', $this->company), [
                'name' => 'Bad', 'account_group_id' => $this->company->accountGroups()->where('code', 'SUNDRY_DEBTORS')->value('id'),
                'opening_balance_type' => 'dr', 'phone' => 'call me', 'email' => 'nope',
            ])
            ->assertSessionHasErrors(['phone', 'email']);
    }
}
