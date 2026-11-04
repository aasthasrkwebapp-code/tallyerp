<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * July: taxable sales 75,000 @18% + exempt sales 25,000 @0% → 25% exempt.
 * Common purchase (office rent) 20,000 @18% → CGST 1,800 + SGST 1,800, 25% reversed = 450 each.
 * Goods purchase 10,000 @18% (not common) → CGST 900 + SGST 900, kept in full.
 */
class Rule42ReversalTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected Account $party;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = User::factory()->create()->companies()->create([
            'name' => 'Acme Traders', 'gstin' => '27AAAAA0000A1Z5', 'financial_year' => '2026-27', 'currency' => 'INR',
        ]);

        $sales = $this->ledger('Sales', 'SALES_ACCOUNTS');
        $exempt = $this->ledger('Exempt Sales', 'SALES_ACCOUNTS');
        $rent = $this->ledger('Office Rent', 'INDIRECT_EXPENSES', ['itc_common' => true]);
        $goods = $this->ledger('Purchases', 'PURCHASE_ACCOUNTS');
        $this->party = $this->ledger('Local Party', 'SUNDRY_DEBTORS', ['state_code' => '27']);

        $this->invoice('sales', $sales, 75000, 18);
        $this->invoice('sales', $exempt, 25000, 0);
        $this->invoice('purchase', $rent, 20000, 18);
        $this->invoice('purchase', $goods, 10000, 18);
    }

    protected function ledger(string $name, string $code, array $extra = []): Account
    {
        $group = $this->company->accountGroups()->where('code', $code)->firstOrFail();

        return $this->company->accounts()->create($extra + ['name' => $name, 'account_group_id' => $group->id, 'account_type' => $group->nature]);
    }

    protected function invoice(string $type, Account $ledger, float $taxable, float $rate): void
    {
        $this->actingAs($this->company->user)
            ->post(route('companies.gst-invoices.store', $this->company), [
                'type' => $type, 'date' => '2026-07-10', 'party_account_id' => $this->party->id,
                'lines' => [['account_id' => $ledger->id, 'taxable' => $taxable, 'rate' => $rate, 'hsn_sac' => '9954']],
            ])
            ->assertSessionHasNoErrors();
    }

    public function test_common_credit_is_reversed_in_ratio_of_exempt_sales(): void
    {
        $response = $this->actingAs($this->company->user)
            ->get(route('companies.gst.gstr3b', [$this->company, 'month' => '2026-07']))
            ->assertOk()
            ->assertSee('4(B)(1) Reversal of common credit')
            ->assertSee('25% exempt sales');

        $reversal = $response->viewData('reversal');
        $this->assertSame([25.0, 450.0, 450.0, 0.0], [$reversal->ratio, $reversal->cgst, $reversal->sgst, $reversal->igst]);

        // Net credit: 1,800 + 900 − 450 = 2,250 per head; output 6,750 per head → 4,500 cash.
        $this->assertSame(2250.0, $response->viewData('itc')->cgst);
        $this->assertSame(4500.0, $response->viewData('payable')->cgst);
    }

    public function test_no_exempt_sales_means_no_reversal(): void
    {
        $response = $this->actingAs($this->company->user)
            ->get(route('companies.gst.gstr3b', [$this->company, 'month' => '2026-08']));

        $this->assertSame(0.0, $response->viewData('reversal')->cgst);
        $response->assertDontSee('4(B)(1) Reversal');
    }

    public function test_ledger_flag_saved_from_form(): void
    {
        $group = $this->company->accountGroups()->where('code', 'INDIRECT_EXPENSES')->value('id');

        $this->actingAs($this->company->user)
            ->post(route('companies.accounts.store', $this->company), [
                'name' => 'Telephone', 'account_group_id' => $group, 'opening_balance_type' => 'dr', 'is_active' => '1', 'itc_common' => '1',
            ])
            ->assertSessionHasNoErrors();

        $this->assertTrue((bool) $this->company->accounts()->where('name', 'Telephone')->value('itc_common'));
    }
}
