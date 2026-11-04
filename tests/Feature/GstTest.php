<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GstTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected Account $sales;

    protected Account $purchases;

    protected Account $localParty;

    protected Account $karnatakaParty;

    protected Account $walkIn;

    protected function setUp(): void
    {
        parent::setUp();

        // Maharashtra company (state code 27).
        $this->company = User::factory()->create()->companies()->create([
            'name' => 'Acme Traders',
            'gstin' => '27AAAAA0000A1Z5',
            'financial_year' => '2026-27',
            'currency' => 'INR',
        ]);

        $this->sales = $this->ledger(['name' => 'Sales', 'hsn_sac' => '8471', 'gst_rate' => 18], 'SALES_ACCOUNTS');
        $this->purchases = $this->ledger(['name' => 'Purchases'], 'PURCHASE_ACCOUNTS');
        $this->localParty = $this->ledger(['name' => 'Pune Stores', 'gstin' => '27BBBBB1111B1Z5', 'state_code' => '27'], 'SUNDRY_DEBTORS');
        $this->karnatakaParty = $this->ledger(['name' => 'Bengaluru Tech', 'gstin' => '29CCCCC2222C1Z5', 'state_code' => '29'], 'SUNDRY_DEBTORS');
        $this->walkIn = $this->ledger(['name' => 'Cash Customer', 'state_code' => '27'], 'SUNDRY_DEBTORS');
    }

    protected function ledger(array $attributes, string $groupCode): Account
    {
        $group = $this->company->accountGroups()->where('code', $groupCode)->firstOrFail();

        return $this->company->accounts()->create($attributes + [
            'account_group_id' => $group->id,
            'account_type' => $group->nature,
        ]);
    }

    protected function invoice(string $type, Account $party, Account $item, float $taxable, float $rate = 18, array $extra = [])
    {
        return $this->actingAs($this->company->user)
            ->post(route('companies.gst-invoices.store', $this->company), $extra + [
                'type' => $type,
                'date' => '2026-07-10',
                'party_account_id' => $party->id,
                'lines' => [
                    ['account_id' => $item->id, 'hsn_sac' => '8471', 'taxable' => $taxable, 'rate' => $rate],
                ],
            ]);
    }

    protected function balanceOf(string $ledgerCode): float
    {
        $account = $this->company->accounts()->where('code', $ledgerCode)->firstOrFail();

        return round($account->entries()->sum('credit') - $account->entries()->sum('debit'), 2);
    }

    public function test_intra_state_sale_splits_cgst_and_sgst(): void
    {
        $this->invoice('sales', $this->localParty, $this->sales, 10000)->assertSessionHasNoErrors();

        $voucher = $this->company->vouchers()->firstOrFail();
        $this->assertSame('11800.00', $voucher->amount);
        $this->assertSame('27', $voucher->place_of_supply);

        $line = $voucher->gstLines()->firstOrFail();
        $this->assertSame(['900.00', '900.00', '0.00'], [$line->cgst, $line->sgst, $line->igst]);

        $this->assertSame(900.0, $this->balanceOf('GST_OUTPUT_CGST'));
        $this->assertSame(900.0, $this->balanceOf('GST_OUTPUT_SGST'));
        $this->assertSame('11800.00', $voucher->entries()->where('account_id', $this->localParty->id)->value('debit'));
        $this->assertSame('10000.00', $voucher->entries()->where('account_id', $this->sales->id)->value('credit'));
    }

    public function test_inter_state_sale_charges_igst(): void
    {
        $this->invoice('sales', $this->karnatakaParty, $this->sales, 10000)->assertSessionHasNoErrors();

        $line = $this->company->vouchers()->firstOrFail()->gstLines()->firstOrFail();
        $this->assertSame(['0.00', '0.00', '1800.00'], [$line->cgst, $line->sgst, $line->igst]);
        $this->assertSame(1800.0, $this->balanceOf('GST_OUTPUT_IGST'));
    }

    public function test_place_of_supply_overrides_party_state(): void
    {
        $this->invoice('sales', $this->localParty, $this->sales, 1000, 18, ['place_of_supply' => '07'])
            ->assertSessionHasNoErrors();

        $this->assertSame('180.00', $this->company->vouchers()->firstOrFail()->gstLines()->value('igst'));
    }

    public function test_purchase_books_input_tax_credit(): void
    {
        $this->invoice('purchase', $this->localParty, $this->purchases, 5000, 12)->assertSessionHasNoErrors();

        // Input tax is a debit balance.
        $this->assertSame(-300.0, $this->balanceOf('GST_INPUT_CGST'));
        $this->assertSame(-300.0, $this->balanceOf('GST_INPUT_SGST'));

        $voucher = $this->company->vouchers()->firstOrFail();
        $this->assertSame('5600.00', $voucher->entries()->where('account_id', $this->localParty->id)->value('credit'));
    }

    public function test_sales_invoice_must_use_sales_ledger(): void
    {
        $this->invoice('sales', $this->localParty, $this->purchases, 1000)
            ->assertSessionHasErrors('lines.0.account_id');
    }

    public function test_company_without_state_cannot_raise_gst_invoice(): void
    {
        $this->company->update(['gstin' => null, 'state' => null]);

        $this->invoice('sales', $this->localParty, $this->sales, 1000)
            ->assertSessionHasErrors('party_account_id');
    }

    public function test_invoice_page_and_trial_balance_still_balance(): void
    {
        $this->invoice('sales', $this->karnatakaParty, $this->sales, 2500)->assertSessionHasNoErrors();
        $voucher = $this->company->vouchers()->firstOrFail();

        $this->actingAs($this->company->user)
            ->get(route('companies.gst-invoices.show', [$this->company, $voucher]))
            ->assertOk()
            ->assertSee('TAX INVOICE')
            ->assertSee('29CCCCC2222C1Z5')
            ->assertSee('2,950.00');

        $tb = $this->actingAs($this->company->user)
            ->get(route('companies.reports.trial-balance', $this->company));
        $this->assertSame($tb->viewData('totalDebit'), $tb->viewData('totalCredit'));
    }

    public function test_gstr1_splits_b2b_b2c_and_hsn(): void
    {
        $this->invoice('sales', $this->karnatakaParty, $this->sales, 10000);
        $this->invoice('sales', $this->walkIn, $this->sales, 2000);
        $this->invoice('sales', $this->walkIn, $this->sales, 1000);

        $response = $this->actingAs($this->company->user)
            ->get(route('companies.gst.gstr1', [$this->company, 'month' => '2026-07']))
            ->assertOk();

        $b2b = $response->viewData('b2b');
        $this->assertCount(1, $b2b);
        $this->assertSame('29CCCCC2222C1Z5', $b2b[0]->gstin);
        $this->assertSame(1800.0, $b2b[0]->igst);

        $b2c = $response->viewData('b2c');
        $this->assertCount(1, $b2c);
        $this->assertSame(3000.0, $b2c[0]->taxable);
        $this->assertSame(270.0, $b2c[0]->cgst);

        $hsn = $response->viewData('hsn');
        $this->assertSame(13000.0, $hsn[0]->taxable);
    }

    public function test_unused_credit_carries_into_next_month(): void
    {
        // June: purchase only → ITC 900 + 900 unused. July: sale with output 900 + 900.
        $this->invoice('purchase', $this->localParty, $this->purchases, 10000, 18, ['date' => '2026-06-10'])->assertSessionHasNoErrors();
        $this->invoice('sales', $this->localParty, $this->sales, 10000, 18, ['date' => '2026-07-10'])->assertSessionHasNoErrors();

        $june = $this->actingAs($this->company->user)
            ->get(route('companies.gst.gstr3b', [$this->company, 'month' => '2026-06']));
        $this->assertSame(900.0, $june->viewData('carryForward')->cgst);

        $july = $this->actingAs($this->company->user)
            ->get(route('companies.gst.gstr3b', [$this->company, 'month' => '2026-07']))
            ->assertSee('Unused credit brought forward');
        $this->assertSame(900.0, $july->viewData('openingCredit')->cgst);
        $this->assertSame(0.0, $july->viewData('payable')->cgst);
        $this->assertSame(0.0, $july->viewData('carryForward')->cgst);
    }

    public function test_gstr3b_nets_output_against_input(): void
    {
        $this->invoice('sales', $this->localParty, $this->sales, 10000);      // CGST 900, SGST 900
        $this->invoice('purchase', $this->localParty, $this->purchases, 5000); // CGST 450, SGST 450

        $response = $this->actingAs($this->company->user)
            ->get(route('companies.gst.gstr3b', [$this->company, 'month' => '2026-07']))
            ->assertOk();

        $this->assertSame(450.0, $response->viewData('payable')->cgst);
        $this->assertSame(450.0, $response->viewData('payable')->sgst);
        $this->assertSame(0.0, $response->viewData('payable')->igst);

        // Another month is empty.
        $other = $this->actingAs($this->company->user)
            ->get(route('companies.gst.gstr3b', [$this->company, 'month' => '2026-08']));
        $this->assertSame(0.0, $other->viewData('outward')->taxable);
    }

    public function test_ledger_gst_fields_saved_and_state_taken_from_gstin(): void
    {
        $group = $this->company->accountGroups()->where('code', 'SUNDRY_CREDITORS')->firstOrFail();

        $this->actingAs($this->company->user)
            ->post(route('companies.accounts.store', $this->company), [
                'name' => 'Delhi Supplier',
                'account_group_id' => $group->id,
                'opening_balance_type' => 'cr',
                'gstin' => '07ddddd3333d1z5',
                'state_code' => '27',
            ])
            ->assertSessionHasNoErrors();

        $account = $this->company->accounts()->where('name', 'Delhi Supplier')->firstOrFail();
        $this->assertSame('07DDDDD3333D1Z5', $account->gstin);
        $this->assertSame('07', $account->state_code);
    }

    public function test_create_page_renders(): void
    {
        $this->actingAs($this->company->user)
            ->get(route('companies.gst-invoices.create', [$this->company, 'type' => 'sales']))
            ->assertOk()
            ->assertSee('Pune Stores')
            ->assertSee('Maharashtra');
    }
}
