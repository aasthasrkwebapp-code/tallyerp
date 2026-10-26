<?php

namespace Tests\Feature\Masters;

use App\Models\Account;
use App\Models\AccountGroup;
use App\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ChartOfAccountsTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_company_gets_the_standard_groups_and_ledgers(): void
    {
        [, $company] = $this->createCompanyWithOwner();

        $this->assertSame(28, AccountGroup::count());
        $this->assertSame(15, AccountGroup::whereNull('parent_id')->count());

        $debtors = AccountGroup::where('system_key', 'sundry_debtors')->first();
        $this->assertSame('asset', $debtors->nature);
        $this->assertSame('current_assets', $debtors->parent->system_key);

        $this->assertTrue(AccountGroup::where('system_key', 'sales_accounts')->value('affects_gross_profit'));
        $this->assertSame('cash', Account::where('system_key', 'cash')->value('account_type'));
        $this->assertSame('igst', Account::where('system_key', 'output_igst')->value('tax_type'));
    }

    public function test_ledger_type_is_derived_from_group(): void
    {
        [$owner] = $this->createCompanyWithOwner();

        $this->actingAs($owner)->post('/ledgers', [
            'name' => 'HDFC Current A/c',
            'account_group_id' => AccountGroup::idFor('bank_accounts'),
            'opening_balance' => '25000',
            'opening_balance_type' => 'Dr',
            'bank_account_number' => '50200012345678',
            'ifsc' => 'hdfc0001234',
            'is_active' => '1',
        ])->assertRedirect(route('ledgers.index'));

        $bank = Account::where('name', 'HDFC Current A/c')->firstOrFail();
        $this->assertSame('bank', $bank->account_type);
        $this->assertSame('HDFC0001234', $bank->ifsc);
        $this->assertSame('25000.00', $bank->opening_balance);
        $this->assertSame(2500000, $bank->openingPaise());

        $this->actingAs($owner)->post('/ledgers', [
            'name' => 'Ravi Enterprises',
            'account_group_id' => AccountGroup::idFor('sundry_creditors'),
            'opening_balance' => '1200.50',
            'opening_balance_type' => 'Cr',
            'maintain_bill_wise' => '1',
            'credit_period_days' => 30,
        ])->assertSessionHasNoErrors();

        $party = Account::where('name', 'Ravi Enterprises')->firstOrFail();
        $this->assertSame('party', $party->account_type);
        $this->assertTrue($party->maintain_bill_wise);
        $this->assertSame(-120050, $party->openingPaise());
        $this->assertNull($party->ifsc);
    }

    public function test_ledger_validation(): void
    {
        [$owner] = $this->createCompanyWithOwner();

        $this->actingAs($owner)->post('/ledgers', [
            'name' => 'Cash', // duplicate of the default ledger
            'account_group_id' => AccountGroup::idFor('cash_in_hand'),
            'opening_balance' => '10.555',
            'opening_balance_type' => 'Dr',
        ])->assertSessionHasErrors(['name', 'opening_balance']);

        $this->actingAs($owner)->post('/ledgers', [
            'name' => 'Rent',
            'account_group_id' => AccountGroup::idFor('indirect_expenses'),
            'opening_balance' => '500',
            'opening_balance_type' => 'Dr',
        ])->assertSessionHasErrors('opening_balance');

        $this->actingAs($owner)->post('/ledgers', [
            'name' => 'Bad Bank',
            'account_group_id' => AccountGroup::idFor('bank_accounts'),
            'opening_balance' => '0',
            'opening_balance_type' => 'Dr',
            'ifsc' => 'BAD',
        ])->assertSessionHasErrors('ifsc');
    }

    public function test_ledger_from_another_company_is_not_reachable(): void
    {
        [$owner] = $this->createCompanyWithOwner(['name' => 'Mine']);
        [, $other] = $this->createCompanyWithOwner(['name' => 'Other']);
        $foreign = Account::withoutGlobalScopes()->where('company_id', $other->id)->where('system_key', 'cash')->first();

        $this->actingAs($owner)->withSession(['current_company_id' => $owner->companies()->first()->id])
            ->get("/ledgers/{$foreign->id}/edit")->assertNotFound();

        $this->actingAs($owner)->withSession(['current_company_id' => $owner->companies()->first()->id])
            ->post('/ledgers', [
                'name' => 'Sneaky',
                'account_group_id' => AccountGroup::withoutGlobalScopes()->where('company_id', $other->id)->value('id'),
                'opening_balance' => '0',
                'opening_balance_type' => 'Dr',
            ])->assertSessionHasErrors('account_group_id');
    }

    public function test_system_ledgers_and_groups_cannot_be_deleted(): void
    {
        [$owner] = $this->createCompanyWithOwner();
        $cash = Account::where('system_key', 'cash')->first();
        $group = AccountGroup::where('system_key', 'sundry_debtors')->first();

        $this->actingAs($owner)->delete("/ledgers/{$cash->id}")->assertSessionHas('error');
        $this->actingAs($owner)->delete("/account-groups/{$group->id}")->assertSessionHas('error');
        $this->assertNotNull($cash->fresh());
        $this->assertNotNull($group->fresh());
    }

    public function test_custom_group_inherits_nature_and_cannot_be_nested_in_itself(): void
    {
        [$owner] = $this->createCompanyWithOwner();

        $this->actingAs($owner)->post('/account-groups', [
            'name' => 'Vehicle Expenses',
            'parent_id' => AccountGroup::idFor('indirect_expenses'),
        ])->assertSessionHasNoErrors();
        $vehicle = AccountGroup::where('name', 'Vehicle Expenses')->first();
        $this->assertSame('expense', $vehicle->nature);

        $this->actingAs($owner)->post('/account-groups', [
            'name' => 'Fuel',
            'parent_id' => $vehicle->id,
        ])->assertSessionHasNoErrors();
        $fuel = AccountGroup::where('name', 'Fuel')->first();

        $this->actingAs($owner)->put("/account-groups/{$vehicle->id}", [
            'name' => 'Vehicle Expenses',
            'parent_id' => $fuel->id,
        ])->assertSessionHasErrors('parent_id');

        // Moving the parent to an income group updates the whole branch.
        $this->actingAs($owner)->put("/account-groups/{$vehicle->id}", [
            'name' => 'Vehicle Expenses',
            'parent_id' => AccountGroup::idFor('indirect_incomes'),
        ])->assertSessionHasNoErrors();
        $this->assertSame('income', $fuel->fresh()->nature);
    }

    public function test_primary_group_requires_nature(): void
    {
        [$owner] = $this->createCompanyWithOwner();

        $this->actingAs($owner)->post('/account-groups', ['name' => 'Odd'])->assertSessionHasErrors('nature');
    }

    public function test_ledger_changes_are_audited(): void
    {
        [$owner] = $this->createCompanyWithOwner();
        $cash = Account::where('system_key', 'cash')->first();

        $this->actingAs($owner)->put("/ledgers/{$cash->id}", [
            'name' => 'Cash in Hand',
            'account_group_id' => $cash->account_group_id,
            'opening_balance' => '5000',
            'opening_balance_type' => 'Dr',
        ])->assertSessionHasNoErrors();

        $log = AuditLog::where('auditable_type', Account::class)->where('auditable_id', $cash->id)->where('event', 'updated')->firstOrFail();
        $this->assertSame('0.00', $log->old_values['opening_balance']);
        $this->assertSame('5000.00', $log->new_values['opening_balance']);
        $this->assertSame('cash', $cash->fresh()->account_type);
    }

    public function test_cost_centres(): void
    {
        [$owner] = $this->createCompanyWithOwner();
        $category = \App\Models\CostCategory::first();

        $this->actingAs($owner)->post('/cost-centres', ['name' => 'Truck MH12AB1234', 'cost_category_id' => $category->id])
            ->assertSessionHasNoErrors();
        $this->actingAs($owner)->post('/cost-centres', ['name' => 'Truck MH12AB1234', 'cost_category_id' => $category->id])
            ->assertSessionHasErrors('name');
        $this->assertSame(1, \App\Models\CostCentre::count());
    }

    public function test_viewer_cannot_create_ledgers(): void
    {
        [, $company] = $this->createCompanyWithOwner();
        $viewer = $this->addMember($company, 'viewer');

        $this->actingAs($viewer)->get('/ledgers')->assertOk();
        $this->actingAs($viewer)->get('/ledgers/create')->assertForbidden();
    }
}
