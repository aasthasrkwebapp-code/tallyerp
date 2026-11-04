<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BudgetTest extends TestCase
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

        $this->cash = $this->ledger('Cash', 'CASH_IN_HAND');
        $this->sales = $this->ledger('Sales', 'SALES_ACCOUNTS');
        $this->rent = $this->ledger('Rent', 'INDIRECT_EXPENSES');

        $this->journal('2026-05-01', $this->cash, $this->sales, 70000);
        $this->journal('2026-06-01', $this->rent, $this->cash, 40000);
    }

    protected function ledger(string $name, string $code): Account
    {
        $group = $this->company->accountGroups()->where('code', $code)->firstOrFail();

        return $this->company->accounts()->create(['name' => $name, 'account_group_id' => $group->id, 'account_type' => $group->nature]);
    }

    protected function journal(string $date, Account $debit, Account $credit, float $amount): void
    {
        $this->actingAs($this->company->user)
            ->post(route('companies.vouchers.store', $this->company), [
                'type' => 'journal', 'date' => $date,
                'entries' => [['account_id' => $debit->id, 'debit' => $amount], ['account_id' => $credit->id, 'credit' => $amount]],
            ])
            ->assertSessionHasNoErrors();
    }

    protected function setBudgets(array $budgets)
    {
        return $this->actingAs($this->company->user)
            ->put(route('companies.budgets.update', $this->company), ['budgets' => $budgets]);
    }

    public function test_budgets_are_saved_only_for_income_and_expense_ledgers(): void
    {
        $this->setBudgets([$this->sales->id => 120000, $this->rent->id => 60000, $this->cash->id => 999])
            ->assertRedirect(route('companies.budgets.index', $this->company));

        $this->assertSame(2, $this->company->budgets()->count());
        $this->assertSame('120000.00', $this->company->budgets()->where('account_id', $this->sales->id)->value('amount'));

        // Blank removes a budget.
        $this->setBudgets([$this->rent->id => '']);
        $this->assertSame(1, $this->company->budgets()->count());
    }

    public function test_budget_vs_actual_for_full_year(): void
    {
        $this->setBudgets([$this->sales->id => 120000, $this->rent->id => 30000]);

        $rows = $this->actingAs($this->company->user)
            ->get(route('companies.budgets.index', $this->company))
            ->assertOk()
            ->viewData('rows')
            ->keyBy(fn ($r) => $r->account->name);

        $this->assertSame(70000.0, $rows['Sales']->actual);
        $this->assertSame(-50000.0, $rows['Sales']->variance); // income below budget
        $this->assertSame(40000.0, $rows['Rent']->actual);
        $this->assertSame(-10000.0, $rows['Rent']->variance); // overspent
        $this->assertSame(133.3, $rows['Rent']->used);
    }

    public function test_budget_to_date_is_pro_rated(): void
    {
        $this->setBudgets([$this->rent->id => 365000]);

        $rows = $this->actingAs($this->company->user)
            ->get(route('companies.budgets.index', [$this->company, 'to' => '2026-06-30']))
            ->viewData('rows')
            ->keyBy(fn ($r) => $r->account->name);

        // 1 Apr to 30 Jun = 91 days of 365.
        $this->assertSame(91000.0, $rows['Rent']->budget_to_date);
        $this->assertSame(51000.0, $rows['Rent']->variance); // underspent → favourable
    }

    public function test_edit_page_and_permissions(): void
    {
        $this->actingAs($this->company->user)
            ->get(route('companies.budgets.edit', $this->company))
            ->assertOk()
            ->assertSee('Rent')
            ->assertDontSee('name="budgets['.$this->cash->id.']"', false);

        $this->actingAs(User::factory()->create())
            ->get(route('companies.budgets.index', $this->company))
            ->assertForbidden();
    }
}
