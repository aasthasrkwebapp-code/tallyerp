<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 2026-27: Cash 1,00,000 Dr, stock 10 @ 1,000 (10,000), Capital 1,10,000 Cr.
 *          Sales 50,000 and rent 5,000 in cash → profit 45,000.
 * 2027-28: Sales 20,000 in cash → profit 20,000.
 */
class YearEndTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected Account $cash;

    protected Account $sales;

    protected Account $rent;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = User::factory()->create()->companies()->create([
            'name' => 'Acme Traders', 'financial_year' => '2026-27', 'currency' => 'INR',
        ]);

        $this->cash = $this->ledger('Cash', 'CASH_IN_HAND', 100000, 'dr');
        $this->ledger('Capital', 'CAPITAL_ACCOUNT', 110000, 'cr');
        $this->sales = $this->ledger('Sales', 'SALES_ACCOUNTS');
        $this->rent = $this->ledger('Rent', 'INDIRECT_EXPENSES');

        $this->company->stockItems()->create([
            'name' => 'Widget',
            'unit_id' => $this->company->units()->value('id'),
            'opening_quantity' => 10,
            'opening_rate' => 1000,
        ]);

        $this->voucher('2026-06-01', $this->cash, $this->sales, 50000);
        $this->voucher('2026-07-01', $this->rent, $this->cash, 5000);
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

    protected function voucher(string $date, Account $debit, Account $credit, float $amount)
    {
        return $this->actingAs($this->company->user)
            ->post(route('companies.vouchers.store', $this->company), [
                'type' => 'journal',
                'date' => $date,
                'entries' => [
                    ['account_id' => $debit->id, 'debit' => $amount],
                    ['account_id' => $credit->id, 'credit' => $amount],
                ],
            ]);
    }

    protected function moveToNextYear(): void
    {
        $this->actingAs($this->company->user)
            ->put(route('companies.financial-year', $this->company), ['year' => 2027])
            ->assertSessionHasNoErrors();

        $this->company->refresh();
        $this->voucher('2027-05-01', $this->cash, $this->sales, 20000)->assertSessionHasNoErrors();
    }

    protected function report(string $name)
    {
        return $this->actingAs($this->company->user)
            ->get(route("companies.reports.$name", $this->company))
            ->assertOk();
    }

    public function test_books_start_year_is_recorded(): void
    {
        $this->assertSame(2026, $this->company->books_start_year);
    }

    public function test_first_year_figures(): void
    {
        $this->assertSame(45000.0, $this->report('profit-and-loss')->viewData('netProfit'));

        $tb = $this->report('trial-balance');
        $this->assertSame(160000.0, $tb->viewData('totalDebit'));
        $this->assertSame(160000.0, $tb->viewData('totalCredit'));
    }

    public function test_changing_year_moves_voucher_dates(): void
    {
        $this->voucher('2027-05-01', $this->cash, $this->sales, 1)->assertSessionHasErrors('date');

        $this->moveToNextYear();

        $this->assertSame('2027-28', $this->company->financial_year);
        $this->voucher('2026-06-01', $this->cash, $this->sales, 1)->assertSessionHasErrors('date');
    }

    public function test_income_and_expenses_restart_in_new_year(): void
    {
        $this->moveToNextYear();

        $pl = $this->report('profit-and-loss');
        $this->assertSame(20000.0, $pl->viewData('netProfit'));
        $pl->assertSee('Opening Stock')->assertDontSee('Rent');
    }

    public function test_balance_sheet_carries_forward_with_retained_profit(): void
    {
        $this->moveToNextYear();

        $bs = $this->report('balance-sheet');
        $this->assertSame(45000.0, $bs->viewData('retained'));
        $this->assertSame(20000.0, $bs->viewData('netProfit'));
        $this->assertSame(175000.0, $bs->viewData('totalAssets'));
        $this->assertSame(175000.0, $bs->viewData('totalLiabilities'));
        $this->assertSame(0.0, $bs->viewData('difference'));
    }

    public function test_trial_balance_in_new_year(): void
    {
        $this->moveToNextYear();

        $tb = $this->report('trial-balance');
        $this->assertSame(-45000.0, $tb->viewData('priorProfitAndLoss'));
        $this->assertSame(175000.0, $tb->viewData('totalDebit'));
        $this->assertSame(175000.0, $tb->viewData('totalCredit'));
    }

    public function test_ledger_statements_follow_the_year(): void
    {
        $this->moveToNextYear();

        $cash = $this->actingAs($this->company->user)
            ->get(route('companies.reports.ledger', [$this->company, 'account' => $this->cash->id]))
            ->viewData('statement');
        $this->assertSame(145000.0, $cash['opening']);
        $this->assertSame(165000.0, $cash['closing']);

        $sales = $this->actingAs($this->company->user)
            ->get(route('companies.reports.ledger', [$this->company, 'account' => $this->sales->id]))
            ->viewData('statement');
        $this->assertSame(0.0, $sales['opening']);
        $this->assertSame(-20000.0, $sales['closing']);
    }

    public function test_old_year_reports_are_still_correct(): void
    {
        $this->moveToNextYear();

        $pl = $this->actingAs($this->company->user)
            ->get(route('companies.reports.profit-and-loss', [$this->company, 'to' => '2027-03-31']));
        $this->assertSame(45000.0, $pl->viewData('netProfit'));
    }

    public function test_cannot_go_before_books_start(): void
    {
        $this->actingAs($this->company->user)
            ->put(route('companies.financial-year', $this->company), ['year' => 2025])
            ->assertSessionHasErrors('year');

        $this->actingAs($this->company->user)
            ->put(route('companies.update', $this->company), [
                'name' => 'Acme Traders', 'financial_year' => '2025-26', 'currency' => 'INR',
            ])
            ->assertSessionHasErrors('financial_year');
    }

    public function test_financial_year_format_is_checked(): void
    {
        $this->actingAs($this->company->user)
            ->put(route('companies.update', $this->company), [
                'name' => 'Acme Traders', 'financial_year' => '2026-29', 'currency' => 'INR',
            ])
            ->assertSessionHasErrors('financial_year');
    }

    public function test_books_start_follows_year_until_first_voucher(): void
    {
        $fresh = $this->company->user->companies()->create([
            'name' => 'New Co', 'financial_year' => '2026-27', 'currency' => 'INR',
        ]);

        $this->actingAs($fresh->user)
            ->put(route('companies.update', $fresh), [
                'name' => 'New Co', 'financial_year' => '2025-26', 'currency' => 'INR',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(2025, $fresh->fresh()->books_start_year);
    }
}
