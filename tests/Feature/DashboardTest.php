<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use App\Support\Dashboard;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = User::factory()->create()->companies()->create([
            'name' => 'Acme Traders', 'financial_year' => '2026-27', 'currency' => 'INR',
        ]);

        $cash = $this->ledger('Cash', 'CASH_IN_HAND', 10000, 'dr');
        $this->ledger('Capital', 'CAPITAL_ACCOUNT', 10000, 'cr');
        $sales = $this->ledger('Sales', 'SALES_ACCOUNTS');
        $customer = $this->ledger('Customer', 'SUNDRY_DEBTORS');
        $supplier = $this->ledger('Supplier', 'SUNDRY_CREDITORS');
        $rent = $this->ledger('Rent', 'INDIRECT_EXPENSES');

        $this->journal('2026-05-10', $customer, $sales, 30000);
        $this->journal('2026-06-15', $cash, $sales, 5000);
        $this->journal('2026-06-20', $rent, $supplier, 4000);
    }

    protected function ledger(string $name, string $code, float $opening = 0, string $type = 'dr'): Account
    {
        $group = $this->company->accountGroups()->where('code', $code)->firstOrFail();

        return $this->company->accounts()->create([
            'name' => $name, 'account_group_id' => $group->id, 'account_type' => $group->nature,
            'opening_balance' => $opening, 'opening_balance_type' => $type,
        ]);
    }

    protected function journal(string $date, Account $debit, Account $credit, float $amount): void
    {
        $this->actingAs($this->company->user)
            ->post(route('companies.vouchers.store', $this->company), [
                'type' => 'journal', 'date' => $date,
                'entries' => [
                    ['account_id' => $debit->id, 'debit' => $amount],
                    ['account_id' => $credit->id, 'credit' => $amount],
                ],
            ])
            ->assertSessionHasNoErrors();
    }

    public function test_figures(): void
    {
        $f = Dashboard::figures($this->company, CarbonImmutable::create(2026, 12, 31));

        $this->assertSame(15000.0, $f->cash_bank);
        $this->assertSame(30000.0, $f->receivables);
        $this->assertSame(4000.0, $f->payables);
        $this->assertSame(35000.0, $f->sales);
        $this->assertSame(31000.0, $f->net_profit);
        $this->assertSame(30000.0, $f->monthly_sales['May']);
        $this->assertSame(5000.0, $f->monthly_sales['Jun']);
        $this->assertCount(12, $f->monthly_sales);
        $this->assertCount(3, $f->recent);
    }

    public function test_company_page_shows_dashboard(): void
    {
        $this->actingAs($this->company->user)
            ->get(route('companies.show', $this->company))
            ->assertOk()
            ->assertSee('Cash &amp; Bank', false)
            ->assertSee('Monthly sales');
    }

    public function test_main_dashboard_lists_companies(): void
    {
        $this->actingAs($this->company->user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Acme Traders')
            ->assertSee('Owner');
    }

    public function test_roles_without_reports_see_no_figures(): void
    {
        $this->seed(RolesSeeder::class);
        $hr = User::factory()->create();
        $this->company->members()->attach($hr->id, ['role_id' => Role::where('name', 'employee')->value('id')]);

        $this->actingAs($hr)
            ->get(route('companies.show', $this->company))
            ->assertOk()
            ->assertDontSee('Monthly sales');

        $this->actingAs($hr)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Acme Traders')
            ->assertDontSee('Receivables');
    }
}
