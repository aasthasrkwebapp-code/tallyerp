<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * June: machinery bought for 1,20,000 @18% → CGST 10,800 + SGST 10,800, used for both
 *       taxable and exempt sales (common). No sales in June → nothing reversed.
 * July: sales 75,000 taxable + 25,000 exempt → 25% exempt.
 *       Monthly common credit = 10,800 / 60 = 180 per head; 25% → 45 reversed per head.
 */
class Rule43ReversalTest extends TestCase
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

        $machinery = $this->ledger('Machinery', 'FIXED_ASSETS', ['itc_common' => true]);
        $sales = $this->ledger('Sales', 'SALES_ACCOUNTS');
        $exempt = $this->ledger('Exempt Sales', 'SALES_ACCOUNTS');
        $this->party = $this->ledger('Local Party', 'SUNDRY_DEBTORS', ['state_code' => '27']);

        $this->invoice('purchase', $machinery, 120000, 18, '2026-06-05', '8462');
        $this->invoice('sales', $sales, 75000, 18, '2026-07-10');
        $this->invoice('sales', $exempt, 25000, 0, '2026-07-12');
    }

    protected function ledger(string $name, string $code, array $extra = []): Account
    {
        $group = $this->company->accountGroups()->where('code', $code)->firstOrFail();

        return $this->company->accounts()->create($extra + ['name' => $name, 'account_group_id' => $group->id, 'account_type' => $group->nature]);
    }

    protected function invoice(string $type, Account $ledger, float $taxable, float $rate, string $date, string $hsn = '9954'): void
    {
        $this->actingAs($this->company->user)
            ->post(route('companies.gst-invoices.store', $this->company), [
                'type' => $type, 'date' => $date, 'party_account_id' => $this->party->id,
                'lines' => [['account_id' => $ledger->id, 'taxable' => $taxable, 'rate' => $rate, 'hsn_sac' => $hsn]],
            ])
            ->assertSessionHasNoErrors();
    }

    protected function gstr3b(string $month)
    {
        return $this->actingAs($this->company->user)
            ->get(route('companies.gst.gstr3b', [$this->company, 'month' => $month]))
            ->assertOk();
    }

    public function test_full_credit_in_purchase_month_with_no_exempt_sales(): void
    {
        $june = $this->gstr3b('2026-06');

        $this->assertSame(10800.0, $june->viewData('itc')->cgst);
        $this->assertSame(0.0, $june->viewData('reversal')->rule43_cgst);
    }

    public function test_one_sixtieth_reversed_in_ratio_of_exempt_sales(): void
    {
        $july = $this->gstr3b('2026-07')->assertSee('Reversal of common credit on capital goods, rule 43');

        $reversal = $july->viewData('reversal');
        $this->assertSame([45.0, 45.0, 0.0, 0.0], [$reversal->rule43_cgst, $reversal->rule43_sgst, $reversal->rule42_cgst, $reversal->igst]);

        // No new purchases in July: credit is the June carry-forward, less the 45 reversed.
        $this->assertSame(-45.0, $july->viewData('itc')->cgst);
    }

    public function test_capital_goods_older_than_five_years_drop_out(): void
    {
        // Move to FY 2031-32 and make the same 25%-exempt sales in May and July 2031.
        $this->actingAs($this->company->user)
            ->put(route('companies.financial-year', $this->company), ['year' => 2031])
            ->assertSessionHasNoErrors();
        $this->company->refresh();

        $sales = $this->company->accounts()->where('name', 'Sales')->firstOrFail();
        $exempt = $this->company->accounts()->where('name', 'Exempt Sales')->firstOrFail();
        foreach (['2031-05', '2031-07'] as $month) {
            $this->invoice('sales', $sales, 75000, 18, $month.'-10');
            $this->invoice('sales', $exempt, 25000, 0, $month.'-12');
        }

        // May 2031: the 60-month window starts June 2026, so the machinery still counts.
        $this->assertSame(45.0, $this->gstr3b('2031-05')->viewData('reversal')->rule43_cgst);
        // July 2031: the window starts August 2026, so it has dropped out.
        $this->assertSame(0.0, $this->gstr3b('2031-07')->viewData('reversal')->rule43_cgst);
    }
}
