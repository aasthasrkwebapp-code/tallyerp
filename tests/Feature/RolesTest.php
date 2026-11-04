<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RolesTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesSeeder::class);

        $this->owner = User::factory()->create();
        $this->company = $this->owner->companies()->create([
            'name' => 'Acme Traders', 'financial_year' => '2026-27', 'currency' => 'INR',
        ]);
    }

    protected function member(string $role): User
    {
        $user = User::factory()->create();
        $this->company->members()->attach($user->id, ['role_id' => Role::where('name', $role)->value('id')]);

        return $user;
    }

    protected function get_(User $user, string $route, array $params = [])
    {
        return $this->actingAs($user)->get(route($route, [$this->company, ...$params]));
    }

    public function test_owner_can_add_change_and_remove_members(): void
    {
        $colleague = User::factory()->create(['email' => 'ravi@example.com']);
        $accountant = Role::where('name', 'accountant')->value('id');
        $viewer = Role::where('name', 'viewer')->value('id');

        $this->actingAs($this->owner)
            ->post(route('companies.members.store', $this->company), ['email' => 'Ravi@Example.com', 'role_id' => $accountant])
            ->assertSessionHasNoErrors();
        $this->assertSame('accountant', $this->company->roleFor($colleague));

        $this->actingAs($this->owner)
            ->put(route('companies.members.update', [$this->company, $colleague]), ['role_id' => $viewer])
            ->assertSessionHasNoErrors();
        $this->assertSame('viewer', $this->company->roleFor($colleague));

        $this->get_($this->owner, 'companies.members.index')->assertOk()->assertSee('ravi@example.com');

        $this->actingAs($this->owner)
            ->delete(route('companies.members.destroy', [$this->company, $colleague]))
            ->assertSessionHasNoErrors();
        $this->assertNull($this->company->roleFor($colleague));
    }

    public function test_cannot_add_unknown_email_owner_or_duplicate(): void
    {
        $role = Role::where('name', 'viewer')->value('id');
        $store = fn (string $email) => $this->actingAs($this->owner)
            ->post(route('companies.members.store', $this->company), ['email' => $email, 'role_id' => $role]);

        $store('nobody@example.com')->assertSessionHasErrors('email');
        $store($this->owner->email)->assertSessionHasErrors('email');

        $this->member('viewer');
        $existing = $this->company->members()->first();
        $store($existing->email)->assertSessionHasErrors('email');

        // "owner" itself can't be handed out.
        $this->actingAs($this->owner)
            ->post(route('companies.members.store', $this->company), [
                'email' => User::factory()->create()->email,
                'role_id' => Role::where('name', 'owner')->value('id'),
            ])
            ->assertSessionHasErrors('role_id');
    }

    public function test_members_see_shared_company_in_list(): void
    {
        $viewer = $this->member('viewer');

        $this->actingAs($viewer)->get(route('companies.index'))->assertOk()->assertSee('Acme Traders');
        $this->actingAs(User::factory()->create())->get(route('companies.index'))->assertDontSee('Acme Traders');
    }

    public function test_viewer_can_read_but_not_write(): void
    {
        $viewer = $this->member('viewer');

        $this->get_($viewer, 'companies.show')->assertOk();
        $this->get_($viewer, 'companies.reports.trial-balance')->assertOk();
        $this->get_($viewer, 'companies.vouchers.index')->assertOk();
        $this->get_($viewer, 'companies.accounts.index')->assertOk();

        $this->get_($viewer, 'companies.vouchers.create')->assertForbidden();
        $this->actingAs($viewer)
            ->post(route('companies.accounts.store', $this->company), ['name' => 'X'])
            ->assertForbidden();
        $this->get_($viewer, 'companies.members.index')->assertForbidden();
    }

    public function test_accountant_works_on_books_but_not_payroll_or_settings(): void
    {
        $accountant = $this->member('accountant');

        $this->get_($accountant, 'companies.vouchers.create')->assertOk();
        $this->get_($accountant, 'companies.accounts.create')->assertOk();
        $this->get_($accountant, 'companies.banking.reconciliation')->assertOk();
        $this->get_($accountant, 'companies.tds.index')->assertOk();

        $this->get_($accountant, 'companies.payroll.index')->assertForbidden();
        $this->get_($accountant, 'companies.edit')->assertForbidden();
        $this->get_($accountant, 'companies.members.index')->assertForbidden();
    }

    public function test_employee_role_only_reaches_payroll(): void
    {
        $hr = $this->member('employee');

        $this->get_($hr, 'companies.payroll.index')->assertOk();
        $this->get_($hr, 'companies.employees.create')->assertOk();
        $this->get_($hr, 'companies.vouchers.index')->assertForbidden();
        $this->get_($hr, 'companies.reports.index')->assertForbidden();
    }

    public function test_sales_role_raises_invoices_and_sees_reports(): void
    {
        $sales = $this->member('sales');

        $this->get_($sales, 'companies.gst-invoices.create', ['type' => 'sales'])->assertOk();
        $this->get_($sales, 'companies.outstanding', ['receivables'])->assertOk();
        $this->get_($sales, 'companies.accounts.create')->assertForbidden();
    }

    public function test_admin_can_manage_but_only_owner_deletes_company(): void
    {
        $admin = $this->member('admin');

        $this->get_($admin, 'companies.members.index')->assertOk();
        $this->get_($admin, 'companies.edit')->assertOk();
        $this->actingAs($admin)->delete(route('companies.destroy', $this->company))->assertForbidden();

        $this->actingAs($this->owner)->delete(route('companies.destroy', $this->company))->assertRedirect();
        $this->assertModelMissing($this->company);
    }

    public function test_sidebar_hides_modules_the_role_cannot_use(): void
    {
        $hr = $this->member('employee');

        $this->get_($hr, 'companies.payroll.index')
            ->assertOk()
            ->assertSee(route('companies.payroll.index', $this->company))
            ->assertDontSee(route('companies.vouchers.index', $this->company))
            ->assertDontSee(route('companies.members.index', $this->company));
    }

    public function test_strangers_are_still_blocked(): void
    {
        $this->get_(User::factory()->create(), 'companies.show')->assertForbidden();
    }
}
