<?php

namespace Tests\Feature\Company;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PermissionTest extends TestCase
{
    use RefreshDatabase;

    public function test_viewer_can_view_but_not_change_settings_or_members(): void
    {
        [, $company] = $this->createCompanyWithOwner();
        $viewer = $this->addMember($company, 'viewer');

        $this->actingAs($viewer)->get('/financial-years')->assertOk();
        $this->actingAs($viewer)->get('/members')->assertOk();
        $this->actingAs($viewer)->get('/settings')->assertForbidden();
        $this->actingAs($viewer)->post('/financial-years')->assertForbidden();
        $this->actingAs($viewer)->post('/members', ['email' => 'x@example.com', 'role_id' => 1])->assertForbidden();
    }

    public function test_accountant_can_lock_periods_but_not_manage_users(): void
    {
        [, $company] = $this->createCompanyWithOwner();
        $accountant = $this->addMember($company, 'accountant');
        $year = $company->currentFinancialYear();

        $this->actingAs($accountant)->post("/financial-years/{$year->id}/lock")->assertRedirect();
        $this->assertTrue($year->fresh()->is_locked);

        $this->actingAs($accountant)->get('/members')->assertForbidden();
    }

    public function test_inactive_members_lose_access(): void
    {
        [, $company] = $this->createCompanyWithOwner();
        $member = $this->addMember($company, 'accountant');
        $company->members()->updateExistingPivot($member->id, ['is_active' => false]);

        $this->actingAs($member)->get('/dashboard')->assertRedirect(route('companies.create'));
    }

    public function test_owner_can_add_member_and_change_role(): void
    {
        [$owner, $company] = $this->createCompanyWithOwner();
        $colleague = User::factory()->create(['email' => 'colleague@example.com']);
        $viewerRole = Role::whereNull('company_id')->where('name', 'viewer')->first();
        $accountantRole = Role::whereNull('company_id')->where('name', 'accountant')->first();

        $this->actingAs($owner)->post('/members', ['email' => 'colleague@example.com', 'role_id' => $viewerRole->id])
            ->assertSessionHasNoErrors();
        $this->assertSame('viewer', $colleague->fresh()->roleFor($company)->name);

        $this->actingAs($owner)->put("/members/{$colleague->id}", ['role_id' => $accountantRole->id, 'is_active' => '1'])
            ->assertSessionHasNoErrors();
        $this->assertSame('accountant', $colleague->fresh()->roleFor($company)->name);
    }

    public function test_last_owner_cannot_be_demoted_or_removed(): void
    {
        [$owner, $company] = $this->createCompanyWithOwner();
        $viewerRole = Role::whereNull('company_id')->where('name', 'viewer')->first();

        $this->actingAs($owner)->put("/members/{$owner->id}", ['role_id' => $viewerRole->id, 'is_active' => '1'])
            ->assertSessionHasErrors('role_id');
        $this->assertSame('owner', $owner->fresh()->roleFor($company)->name);
    }

    public function test_custom_role_permissions_are_enforced(): void
    {
        [$owner, $company] = $this->createCompanyWithOwner();
        $periodsView = \App\Models\Permission::where('key', 'periods.view')->first();

        $this->actingAs($owner)->post('/roles', [
            'name' => 'auditor',
            'permissions' => [$periodsView->id],
        ])->assertRedirect(route('members.index'));

        $role = Role::where('company_id', $company->id)->where('name', 'auditor')->firstOrFail();
        $auditor = User::factory()->create();
        $company->members()->attach($auditor->id, ['role_id' => $role->id, 'is_active' => true]);

        $this->actingAs($auditor)->get('/financial-years')->assertOk();
        $this->actingAs($auditor)->get('/audit-logs')->assertForbidden();
    }

    public function test_system_roles_cannot_be_edited(): void
    {
        [$owner] = $this->createCompanyWithOwner();
        $viewerRole = Role::whereNull('company_id')->where('name', 'viewer')->first();

        $this->actingAs($owner)->get("/roles/{$viewerRole->id}/edit")->assertForbidden();
        $this->actingAs($owner)->get("/roles/{$viewerRole->id}")->assertOk();
    }
}
