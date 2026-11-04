<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EInvoiceTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected Account $sales;

    protected Account $buyer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = User::factory()->create()->companies()->create([
            'name' => 'Acme Traders', 'legal_name' => 'Acme Traders Pvt Ltd', 'gstin' => '27AAAAA0000A1Z5',
            'address' => '12 MG Road', 'city' => 'Pune', 'pincode' => '411001', 'phone' => '+91 98765 43210',
            'financial_year' => '2026-27', 'currency' => 'INR',
        ]);

        $group = fn (string $code) => $this->company->accountGroups()->where('code', $code)->firstOrFail();
        $this->sales = $this->company->accounts()->create(['name' => 'Sales', 'account_group_id' => $group('SALES_ACCOUNTS')->id, 'account_type' => 'income']);
        $this->buyer = $this->company->accounts()->create([
            'name' => 'Bengaluru Tech Pvt Ltd', 'account_group_id' => $group('SUNDRY_DEBTORS')->id, 'account_type' => 'assets',
            'gstin' => '29CCCCC2222C1Z5', 'state_code' => '29', 'address' => '5 Residency Road', 'city' => 'Bengaluru', 'pincode' => '560025',
        ]);
    }

    protected function invoice(string $type = 'sales', ?Account $party = null, string $hsn = '8471')
    {
        $laptop = $this->company->stockItems()->create([
            'name' => 'Laptop '.uniqid(), 'unit_id' => $this->company->units()->where('symbol', 'Nos')->value('id'),
        ]);

        $this->actingAs($this->company->user)
            ->post(route('companies.gst-invoices.store', $this->company), [
                'type' => $type, 'date' => '2026-08-14', 'party_account_id' => ($party ?? $this->buyer)->id,
                'lines' => [
                    ['account_id' => $this->sales->id, 'stock_item_id' => $laptop->id, 'quantity' => 5, 'item_rate' => 2000, 'hsn_sac' => $hsn, 'rate' => 18, 'description' => 'Laptop'],
                    ['account_id' => $this->sales->id, 'taxable' => 3000, 'hsn_sac' => '998314', 'rate' => 18, 'description' => 'Setup service'],
                ],
            ])
            ->assertSessionHasNoErrors();

        return $this->company->vouchers()->latest('id')->firstOrFail();
    }

    protected function download($voucher)
    {
        return $this->actingAs($this->company->user)
            ->get(route('companies.gst-invoices.e-invoice', [$this->company, $voucher]));
    }

    public function test_json_matches_schema_fields(): void
    {
        $voucher = $this->invoice();

        $response = $this->download($voucher)->assertOk();
        $data = json_decode($response->streamedContent(), true)[0];

        $this->assertSame('1.1', $data['Version']);
        $this->assertSame(['TaxSch' => 'GST', 'SupTyp' => 'B2B', 'RegRev' => 'N', 'IgstOnIntra' => 'N'], $data['TranDtls']);
        $this->assertSame(['Typ' => 'INV', 'No' => '1/2026-27', 'Dt' => '14/08/2026'], $data['DocDtls']);

        $this->assertSame('Acme Traders Pvt Ltd', $data['SellerDtls']['LglNm']);
        $this->assertSame(411001, $data['SellerDtls']['Pin']);
        $this->assertSame('27', $data['SellerDtls']['Stcd']);
        $this->assertSame('919876543210', $data['SellerDtls']['Ph']);

        $this->assertSame('29CCCCC2222C1Z5', $data['BuyerDtls']['Gstin']);
        $this->assertSame('29', $data['BuyerDtls']['Pos']);
        $this->assertSame(560025, $data['BuyerDtls']['Pin']);

        [$goods, $service] = $data['ItemList'];
        $this->assertEquals(['N', 'NOS', 5.0, 2000.0, 10000.0, 1800.0, 11800.0],
            [$goods['IsServc'], $goods['Unit'], $goods['Qty'], $goods['UnitPrice'], $goods['AssAmt'], $goods['IgstAmt'], $goods['TotItemVal']]);
        $this->assertSame(['Y', 'OTH', '998314'], [$service['IsServc'], $service['Unit'], $service['HsnCd']]);

        $this->assertEquals(13000.0, $data['ValDtls']['AssVal']);
        $this->assertEquals(2340.0, $data['ValDtls']['IgstVal']);
        $this->assertEquals(15340.0, $data['ValDtls']['TotInvVal']);
    }

    public function test_credit_note_uses_crn(): void
    {
        $note = $this->invoice('credit_note');

        $data = json_decode($this->download($note)->streamedContent(), true)[0];
        $this->assertSame('CRN', $data['DocDtls']['Typ']);
    }

    public function test_missing_details_are_listed_instead_of_downloading(): void
    {
        $this->company->update(['pincode' => null]);
        $voucher = $this->invoice(hsn: '');

        $this->download($voucher)
            ->assertRedirect()
            ->assertSessionHas('einvoice_problems', fn ($p) => in_array('Company PIN code is missing.', $p)
                && in_array('Line 1 needs a 4–8 digit HSN/SAC code.', $p));
    }

    public function test_unregistered_buyer_is_not_e_invoiced(): void
    {
        $walkIn = $this->company->accounts()->create([
            'name' => 'Walk-in', 'account_group_id' => $this->buyer->account_group_id, 'account_type' => 'assets', 'state_code' => '27',
        ]);
        $voucher = $this->invoice(party: $walkIn);

        $this->download($voucher)
            ->assertSessionHas('einvoice_problems', fn ($p) => in_array('Buyer GSTIN (e-invoice is for registered buyers) is missing.', $p));
    }

    public function test_invoice_page_offers_button_only_for_sales_side(): void
    {
        $this->actingAs($this->company->user)
            ->get(route('companies.gst-invoices.show', [$this->company, $this->invoice()]))
            ->assertSee('e-Invoice JSON');
    }

    public function test_city_and_pincode_saved_on_company_and_ledger(): void
    {
        $this->actingAs($this->company->user)
            ->put(route('companies.update', $this->company), [
                'name' => 'Acme Traders', 'financial_year' => '2026-27', 'currency' => 'INR',
                'city' => 'Mumbai', 'pincode' => '400001',
            ])
            ->assertSessionHasNoErrors();
        $this->assertSame('400001', $this->company->fresh()->pincode);

        $this->actingAs($this->company->user)
            ->put(route('companies.update', $this->company), [
                'name' => 'Acme Traders', 'financial_year' => '2026-27', 'currency' => 'INR', 'pincode' => '4001',
            ])
            ->assertSessionHasErrors('pincode');
    }
}
