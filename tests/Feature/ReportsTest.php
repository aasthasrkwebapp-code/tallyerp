<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Books used throughout:
 *   Opening: Cash 1,00,000 Dr / Capital 1,00,000 Cr
 *   Receipt: Cash Dr 50,000 / Sales Cr 50,000
 *   Payment: Purchases Dr 30,000 / Cash Cr 30,000
 *   Payment: Rent Dr 5,000 / Cash Cr 5,000
 * Gross profit 20,000, net profit 15,000, cash 1,15,000.
 */
class ReportsTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected Account $cash;

    protected Account $capital;

    protected Account $sales;

    protected Account $purchases;

    protected Account $rent;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = User::factory()->create()->companies()->create([
            'name' => 'Acme Traders',
            'financial_year' => '2026-27',
            'currency' => 'INR',
        ]);

        $this->cash = $this->ledger('Cash', 'CASH_IN_HAND', 100000, 'dr');
        $this->capital = $this->ledger('Capital', 'CAPITAL_ACCOUNT', 100000, 'cr');
        $this->sales = $this->ledger('Sales', 'SALES_ACCOUNTS');
        $this->purchases = $this->ledger('Purchases', 'PURCHASE_ACCOUNTS');
        $this->rent = $this->ledger('Rent', 'INDIRECT_EXPENSES');

        $this->voucher('receipt', '2026-05-01', $this->cash, $this->sales, 50000);
        $this->voucher('payment', '2026-05-10', $this->purchases, $this->cash, 30000);
        $this->voucher('payment', '2026-06-01', $this->rent, $this->cash, 5000);
    }

    protected function ledger(string $name, string $code, float $opening = 0, string $type = 'dr'): Account
    {
        $group = $this->company->accountGroups()->where('code', $code)->firstOrFail();

        return $this->company->accounts()->create([
            'name' => $name,
            'account_group_id' => $group->id,
            'account_type' => $group->nature,
            'opening_balance' => $opening,
            'opening_balance_type' => $type,
        ]);
    }

    protected function voucher(string $type, string $date, Account $debit, Account $credit, float $amount): void
    {
        $this->actingAs($this->company->user)
            ->post(route('companies.vouchers.store', $this->company), [
                'type' => $type,
                'date' => $date,
                'entries' => [
                    ['account_id' => $debit->id, 'debit' => $amount],
                    ['account_id' => $credit->id, 'credit' => $amount],
                ],
            ])
            ->assertSessionHasNoErrors();
    }

    protected function report(string $name, array $query = [])
    {
        return $this->actingAs($this->company->user)
            ->get(route("companies.reports.$name", [$this->company, ...$query]));
    }

    public function test_trial_balance_totals_match(): void
    {
        $response = $this->report('trial-balance')->assertOk();

        $this->assertSame(150000.0, $response->viewData('totalDebit'));
        $this->assertSame(150000.0, $response->viewData('totalCredit'));
        $response->assertSee('115,000.00')
            ->assertDontSee('Opening balances differ');
    }

    public function test_profit_and_loss(): void
    {
        $response = $this->report('profit-and-loss')->assertOk();

        $this->assertSame(20000.0, $response->viewData('grossProfit'));
        $this->assertSame(15000.0, $response->viewData('netProfit'));
        $response->assertSee('Net Profit');
    }

    public function test_balance_sheet_balances(): void
    {
        $response = $this->report('balance-sheet')->assertOk();

        $this->assertSame(115000.0, $response->viewData('totalAssets'));
        $this->assertSame(115000.0, $response->viewData('totalLiabilities'));
        $this->assertSame(0.0, $response->viewData('difference'));
    }

    public function test_balance_sheet_shows_opening_difference(): void
    {
        $this->capital->update(['opening_balance' => 90000]);

        $response = $this->report('balance-sheet')->assertOk();

        $this->assertSame(10000.0, $response->viewData('difference'));
        $response->assertSee('Difference in opening balances');
    }

    public function test_reports_respect_as_on_date(): void
    {
        // Before the rent payment, net profit is the full gross profit.
        $response = $this->report('profit-and-loss', ['to' => '2026-05-31']);

        $this->assertSame(20000.0, $response->viewData('netProfit'));
    }

    public function test_ledger_statement_has_running_balance(): void
    {
        $response = $this->report('ledger', ['account' => $this->cash->id])->assertOk();

        $statement = $response->viewData('statement');
        $this->assertSame(100000.0, $statement['opening']);
        $this->assertSame([150000.0, 120000.0, 115000.0], $statement['rows']->pluck('balance')->all());
        $this->assertSame(115000.0, $statement['closing']);
        $this->assertSame('Sales', $statement['rows'][0]->particulars);
    }

    public function test_ledger_opening_includes_earlier_vouchers(): void
    {
        $response = $this->report('ledger', ['account' => $this->cash->id, 'from' => '2026-05-15']);

        $statement = $response->viewData('statement');
        $this->assertSame(120000.0, $statement['opening']);
        $this->assertCount(1, $statement['rows']);
    }

    public function test_cannot_view_another_companys_ledger(): void
    {
        $other = User::factory()->create()->companies()->create([
            'name' => 'Other', 'financial_year' => '2026-27', 'currency' => 'INR',
        ]);

        $this->actingAs($other->user)
            ->get(route('companies.reports.ledger', [$other, 'account' => $this->cash->id]))
            ->assertNotFound();

        $this->actingAs($other->user)
            ->get(route('companies.reports.balance-sheet', $this->company))
            ->assertForbidden();
    }
}
