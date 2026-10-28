<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Smoke test: every main screen renders for the company owner.
 */
class PagesRenderTest extends TestCase
{
    use RefreshDatabase;

    public function test_main_screens_render(): void
    {
        [$owner, $company] = $this->createCompanyWithOwner();
        $role = \App\Models\Role::whereNull('company_id')->where('name', 'viewer')->first();

        $pages = [
            '/dashboard', '/search', '/search?q=sales', '/reports/ratios', '/reports/comparison', '/budgets', '/budgets/create', '/reports/comparison?statement=bs&mode=quarter', '/companies', '/companies/create', "/companies/{$company->id}", "/companies/{$company->id}/edit",
            '/settings', '/financial-years', '/members', '/roles/create', "/roles/{$role->id}", '/audit-logs', '/profile',
            '/account-groups', '/account-groups/create', '/ledgers', '/ledgers/create', '/cost-centres',
        ];
        $ledger = \App\Models\Account::first();
        $group = \App\Models\AccountGroup::first();
        $pages[] = "/ledgers/{$ledger->id}/edit";
        $pages[] = "/account-groups/{$group->id}/edit";

        foreach ($pages as $page) {
            $this->actingAs($owner)->get($page)->assertOk();
        }

        $log = \App\Models\AuditLog::where("company_id", $company->id)->firstOrFail();
        $this->actingAs($owner)->get("/audit-logs/{$log->id}")->assertOk();
    }
}
