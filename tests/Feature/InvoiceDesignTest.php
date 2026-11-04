<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use App\Support\AmountInWords;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvoiceDesignTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = User::factory()->create()->companies()->create([
            'name' => 'Acme Traders', 'gstin' => '27AAAAA0000A1Z5', 'financial_year' => '2026-27', 'currency' => 'INR',
        ]);
    }

    protected function saveSettings(array $settings)
    {
        return $this->actingAs($this->company->user)
            ->put(route('companies.update', $this->company), $settings + [
                'name' => 'Acme Traders', 'gstin' => '27AAAAA0000A1Z5', 'financial_year' => '2026-27', 'currency' => 'INR',
            ]);
    }

    protected function sale(): \App\Models\Voucher
    {
        $group = fn (string $code) => $this->company->accountGroups()->where('code', $code)->value('id');
        $sales = $this->company->accounts()->create(['name' => 'Sales', 'account_group_id' => $group('SALES_ACCOUNTS'), 'account_type' => 'income']);
        $customer = $this->company->accounts()->create(['name' => 'Pune Stores', 'account_group_id' => $group('SUNDRY_DEBTORS'), 'account_type' => 'assets', 'state_code' => '27']);

        $this->actingAs($this->company->user)
            ->post(route('companies.gst-invoices.store', $this->company), [
                'type' => 'sales', 'date' => '2026-07-01', 'party_account_id' => $customer->id,
                'lines' => [['account_id' => $sales->id, 'taxable' => 104000, 'rate' => 18, 'hsn_sac' => '8471', 'description' => 'Laptops']],
            ])
            ->assertSessionHasNoErrors();

        return $this->company->vouchers()->firstOrFail();
    }

    public function test_amount_in_words_indian_style(): void
    {
        $this->assertSame('Rupees One Lakh Twenty-Two Thousand Seven Hundred Twenty and Fifty Paise Only', AmountInWords::rupees(122720.50));
        $this->assertSame('Rupees One Crore Only', AmountInWords::rupees(10000000));
        $this->assertSame('Rupees Zero Only', AmountInWords::rupees(0));
        $this->assertSame('Rupees Ninety-Nine Only', AmountInWords::rupees(99));
    }

    public function test_invoice_settings_are_validated_and_saved(): void
    {
        $this->saveSettings([
            'invoice_template' => 'modern', 'bank_name' => 'HDFC Bank', 'bank_account_no' => '50100123456789',
            'bank_ifsc' => 'hdfc0001234', 'upi_id' => 'acme@okhdfcbank', 'invoice_terms' => 'Goods once sold will not be taken back.',
        ])->assertSessionHasNoErrors();

        $this->company->refresh();
        $this->assertSame(['modern', 'HDFC0001234', 'acme@okhdfcbank'], [$this->company->invoice_template, $this->company->bank_ifsc, $this->company->upi_id]);

        $this->saveSettings(['bank_ifsc' => 'BAD'])->assertSessionHasErrors('bank_ifsc');
        $this->saveSettings(['upi_id' => 'not a upi'])->assertSessionHasErrors('upi_id');
        $this->saveSettings(['invoice_template' => 'fancy'])->assertSessionHasErrors('invoice_template');
    }

    public function test_invoice_shows_design_words_bank_upi_and_signature(): void
    {
        $this->saveSettings([
            'invoice_template' => 'modern', 'bank_name' => 'HDFC Bank', 'bank_account_no' => '50100123456789',
            'bank_ifsc' => 'HDFC0001234', 'upi_id' => 'acme@okhdfcbank', 'invoice_terms' => 'Subject to Pune jurisdiction.',
        ]);
        $sale = $this->sale();

        $this->actingAs($this->company->user)
            ->get(route('companies.gst-invoices.show', [$this->company, $sale]))
            ->assertOk()
            ->assertSee('invoice-modern')
            ->assertSee('Rupees One Lakh Twenty-Two Thousand Seven Hundred Twenty Only')
            ->assertSee('A/c 50100123456789 · IFSC HDFC0001234')
            ->assertSee('Subject to Pune jurisdiction.')
            ->assertSee('upi://pay?pa=acme%40okhdfcbank', false)
            ->assertSee('am=122720.00', false)
            ->assertSee('Authorised signatory')
            ->assertSee('Download PDF');
    }

    public function test_thermal_receipts(): void
    {
        $sale = $this->sale();

        $this->actingAs($this->company->user)
            ->get(route('companies.gst-invoices.receipt', [$this->company, $sale, 'width' => 58]))
            ->assertOk()
            ->assertSee('size: 58mm auto', false)
            ->assertSee('TAX INVOICE')
            ->assertSee('₹ 1,22,720.00');

        $this->actingAs($this->company->user)
            ->get(route('companies.gst-invoices.receipt', [$this->company, $sale, 'width' => 999]))
            ->assertSee('size: 80mm auto', false);
    }

    public function test_purchase_invoice_has_no_payment_qr(): void
    {
        $this->saveSettings(['upi_id' => 'acme@okhdfcbank']);
        $sale = $this->sale();
        $sale->update(['type' => 'purchase']);

        $this->actingAs($this->company->user)
            ->get(route('companies.gst-invoices.show', [$this->company, $sale]))
            ->assertDontSee('upi://pay', false);
    }
}
