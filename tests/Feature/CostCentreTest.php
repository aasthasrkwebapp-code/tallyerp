<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Company;
use App\Models\CostCentre;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CostCentreTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected Account $cash;

    protected Account $sales;

    protected Account $rent;

    protected CostCentre $pune;

    protected CostCentre $mumbai;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = User::factory()->create()->companies()->create([
            'name' => 'Acme Traders', 'financial_year' => '2026-27', 'currency' => 'INR',
        ]);

        $this->cash = $this->ledger('Cash', 'CASH_IN_HAND');
        $this->sales = $this->ledger('Sales', 'SALES_ACCOUNTS');
        $this->rent = $this->ledger('Rent', 'INDIRECT_EXPENSES');

        $this->actingAs($this->company->user)
            ->post(route('companies.cost-centres.store', $this->company), ['name' => 'Pune Branch'])
            ->assertSessionHasNoErrors();
        $this->actingAs($this->company->user)
            ->post(route('companies.cost-centres.store', $this->company), ['name' => 'Mumbai Branch'])
            ->assertSessionHasNoErrors();

        $this->pune = $this->company->costCentres()->where('name', 'Pune Branch')->firstOrFail();
        $this->mumbai = $this->company->costCentres()->where('name', 'Mumbai Branch')->firstOrFail();
    }

    protected function ledger(string $name, string $code): Account
    {
        $group = $this->company->accountGroups()->where('code', $code)->firstOrFail();

        return $this->company->accounts()->create([
            'name' => $name, 'account_group_id' => $group->id, 'account_type' => $group->nature,
        ]);
    }

    protected function voucher(Account $debit, Account $credit, float $amount, ?CostCentre $debitCentre = null, ?CostCentre $creditCentre = null)
    {
        return $this->actingAs($this->company->user)
            ->post(route('companies.vouchers.store', $this->company), [
                'type' => 'journal',
                'date' => '2026-06-01',
                'entries' => [
                    ['account_id' => $debit->id, 'debit' => $amount, 'cost_centre_id' => $debitCentre?->id],
                    ['account_id' => $credit->id, 'credit' => $amount, 'cost_centre_id' => $creditCentre?->id],
                ],
            ]);
    }

    public function test_report_shows_income_and_expense_per_centre(): void
    {
        $this->voucher($this->cash, $this->sales, 50000, null, $this->pune)->assertSessionHasNoErrors();
        $this->voucher($this->rent, $this->cash, 8000, $this->pune)->assertSessionHasNoErrors();
        $this->voucher($this->rent, $this->cash, 3000, $this->mumbai)->assertSessionHasNoErrors();
        $this->voucher($this->rent, $this->cash, 1000)->assertSessionHasNoErrors();

        $response = $this->actingAs($this->company->user)
            ->get(route('companies.cost-centres.index', $this->company))
            ->assertOk()
            ->assertSee('Pune Branch');

        $rows = $response->viewData('rows')->keyBy(fn ($r) => $r->centre->name);
        $this->assertSame(50000.0, $rows['Pune Branch']->income);
        $this->assertSame(8000.0, $rows['Pune Branch']->expenses);
        $this->assertSame(42000.0, $rows['Pune Branch']->net);
        $this->assertSame(-3000.0, $rows['Mumbai Branch']->net);
        $this->assertSame(1000.0, $response->viewData('unallocated')->expenses);
    }

    public function test_voucher_form_shows_cost_centre_column(): void
    {
        $this->actingAs($this->company->user)
            ->get(route('companies.vouchers.create', $this->company))
            ->assertOk()
            ->assertSee('Cost centre')
            ->assertSee('Mumbai Branch');
    }

    public function test_cost_centre_kept_when_editing(): void
    {
        $this->voucher($this->rent, $this->cash, 500, $this->pune);
        $voucher = $this->company->vouchers()->firstOrFail();

        $this->actingAs($this->company->user)
            ->put(route('companies.vouchers.update', [$this->company, $voucher]), [
                'date' => '2026-06-01',
                'entries' => [
                    ['account_id' => $this->rent->id, 'debit' => 600, 'cost_centre_id' => $this->mumbai->id],
                    ['account_id' => $this->cash->id, 'credit' => 600],
                ],
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame($this->mumbai->id, $voucher->entries()->where('account_id', $this->rent->id)->value('cost_centre_id'));
    }

    public function test_used_centre_cannot_be_deleted_and_names_are_unique(): void
    {
        $this->voucher($this->rent, $this->cash, 500, $this->pune);

        $this->actingAs($this->company->user)
            ->delete(route('companies.cost-centres.destroy', [$this->company, $this->pune]))
            ->assertSessionHas('error');
        $this->assertModelExists($this->pune);

        $this->actingAs($this->company->user)
            ->delete(route('companies.cost-centres.destroy', [$this->company, $this->mumbai]))
            ->assertSessionHas('success');
        $this->assertModelMissing($this->mumbai);

        $this->actingAs($this->company->user)
            ->post(route('companies.cost-centres.store', $this->company), ['name' => 'Pune Branch'])
            ->assertSessionHasErrors('name');
    }

    public function test_cannot_use_another_companys_cost_centre(): void
    {
        $other = User::factory()->create()->companies()->create([
            'name' => 'Other', 'financial_year' => '2026-27', 'currency' => 'INR',
        ]);
        $foreign = $other->costCentres()->create(['name' => 'Foreign']);

        $this->voucher($this->rent, $this->cash, 500, $foreign)
            ->assertSessionHasErrors('entries.0.cost_centre_id');

        $this->actingAs($other->user)
            ->get(route('companies.cost-centres.index', $this->company))
            ->assertForbidden();
    }
}
