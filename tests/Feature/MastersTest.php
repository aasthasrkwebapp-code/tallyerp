<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MastersTest extends TestCase
{
    use RefreshDatabase;

    protected function makeCompany(?User $user = null): Company
    {
        $user ??= User::factory()->create();

        return $user->companies()->create([
            'name' => 'Acme Traders',
            'financial_year' => '2026-27',
            'currency' => 'INR',
        ]);
    }

    public function test_new_company_gets_tallys_28_default_groups(): void
    {
        $company = $this->makeCompany();

        $this->assertSame(28, $company->accountGroups()->count());
        $this->assertSame(15, $company->accountGroups()->where('is_primary', true)->count());

        $debtors = $company->accountGroups()->where('code', 'SUNDRY_DEBTORS')->first();
        $this->assertSame('Current Assets', $debtors->parent->name);
        $this->assertSame('assets', $debtors->nature);
    }

    public function test_two_companies_can_have_the_same_group_codes(): void
    {
        $user = User::factory()->create();
        $this->makeCompany($user);
        $this->makeCompany($user);

        $this->assertDatabaseCount('account_groups', 56);
    }

    public function test_owner_can_create_sub_group_that_inherits_nature(): void
    {
        $company = $this->makeCompany();
        $creditors = $company->accountGroups()->where('code', 'SUNDRY_CREDITORS')->first();

        $this->actingAs($company->user)
            ->post(route('companies.account-groups.store', $company), [
                'name' => 'Local Suppliers',
                'parent_id' => $creditors->id,
            ])
            ->assertRedirect(route('companies.account-groups.index', $company));

        $this->assertDatabaseHas('account_groups', [
            'company_id' => $company->id,
            'name' => 'Local Suppliers',
            'parent_id' => $creditors->id,
            'nature' => 'liabilities',
            'is_primary' => false,
        ]);
    }

    public function test_predefined_group_can_be_renamed_but_not_deleted(): void
    {
        $company = $this->makeCompany();
        $group = $company->accountGroups()->where('code', 'CAPITAL_ACCOUNT')->first();

        $this->actingAs($company->user)
            ->put(route('companies.account-groups.update', [$company, $group]), ['name' => "Owner's Capital"])
            ->assertRedirect();
        $this->assertSame("Owner's Capital", $group->fresh()->name);
        $this->assertNull($group->fresh()->parent_id);

        $this->actingAs($company->user)
            ->delete(route('companies.account-groups.destroy', [$company, $group]))
            ->assertSessionHas('error');
        $this->assertModelExists($group);
    }

    public function test_owner_can_create_edit_and_delete_ledger(): void
    {
        $company = $this->makeCompany();
        $cash = $company->accountGroups()->where('code', 'CASH_IN_HAND')->first();

        $this->actingAs($company->user)
            ->post(route('companies.accounts.store', $company), [
                'name' => 'Cash',
                'account_group_id' => $cash->id,
                'opening_balance' => '5000',
                'opening_balance_type' => 'dr',
                'is_active' => '1',
            ])
            ->assertRedirect(route('companies.accounts.index', $company));

        $account = $company->accounts()->firstOrFail();
        $this->assertSame('assets', $account->account_type);
        $this->assertSame(5000.0, $account->signedOpeningBalance());

        $this->actingAs($company->user)
            ->get(route('companies.accounts.index', $company))
            ->assertOk()
            ->assertSee('Cash');

        $this->actingAs($company->user)
            ->put(route('companies.accounts.update', [$company, $account]), [
                'name' => 'Cash in Hand',
                'account_group_id' => $cash->id,
                'opening_balance' => '200',
                'opening_balance_type' => 'cr',
                'is_active' => '0',
            ])
            ->assertRedirect();

        $account->refresh();
        $this->assertSame('Cash in Hand', $account->name);
        $this->assertSame(-200.0, $account->signedOpeningBalance());
        $this->assertFalse($account->is_active);

        $this->actingAs($company->user)
            ->delete(route('companies.accounts.destroy', [$company, $account]))
            ->assertRedirect();
        $this->assertModelMissing($account);
    }

    public function test_ledger_cannot_use_another_companys_group(): void
    {
        $company = $this->makeCompany();
        $other = $this->makeCompany();
        $foreignGroup = $other->accountGroups()->first();

        $this->actingAs($company->user)
            ->post(route('companies.accounts.store', $company), [
                'name' => 'Sneaky',
                'account_group_id' => $foreignGroup->id,
                'opening_balance_type' => 'dr',
            ])
            ->assertSessionHasErrors('account_group_id');
    }

    public function test_other_users_cannot_see_or_change_masters(): void
    {
        $company = $this->makeCompany();
        $intruder = User::factory()->create();

        $this->actingAs($intruder)
            ->get(route('companies.accounts.index', $company))
            ->assertForbidden();

        $this->actingAs($intruder)
            ->get(route('companies.account-groups.index', $company))
            ->assertForbidden();
    }

    public function test_ledger_from_another_company_is_not_found_in_url(): void
    {
        $company = $this->makeCompany();
        $other = $this->makeCompany($company->user);
        $account = $other->accounts()->create([
            'name' => 'Bank',
            'account_group_id' => $other->accountGroups()->first()->id,
            'account_type' => 'assets',
        ]);

        $this->actingAs($company->user)
            ->get(route('companies.accounts.edit', [$company, $account]))
            ->assertNotFound();
    }
}
