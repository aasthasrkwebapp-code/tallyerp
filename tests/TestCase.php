<?php

namespace Tests;

use App\Http\Middleware\EnsureCompanySelected;
use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use App\Services\CompanySetupService;
use App\Support\CurrentCompany;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /** Seed permissions and system roles for every test that refreshes the database. */
    protected function setUp(): void
    {
        parent::setUp();

        if (in_array(\Illuminate\Foundation\Testing\RefreshDatabase::class, class_uses_recursive($this), true)) {
            $this->seed(RolesSeeder::class);
        }
    }

    /** Create a user who owns a fresh company (FY 2026-27) and make it active. */
    protected function createCompanyWithOwner(array $attributes = [], ?User $owner = null): array
    {
        $owner ??= User::factory()->create();

        $company = app(CompanySetupService::class)->create($owner, array_merge([
            'name' => 'Acme Traders',
            'state' => 'Maharashtra',
            'financial_year' => '2026-27',
            'currency' => 'INR',
        ], $attributes));

        $this->useCompany($company);

        return [$owner, $company];
    }

    protected function useCompany(Company $company): void
    {
        app(CurrentCompany::class)->set($company);
        $this->withSession([EnsureCompanySelected::SESSION_KEY => $company->id]);
    }

    /** Add a user to a company with one of the system roles. */
    protected function addMember(Company $company, string $roleName, ?User $user = null): User
    {
        $user ??= User::factory()->create();
        $roleId = Role::whereNull('company_id')->where('name', $roleName)->value('id');
        $company->members()->attach($user->id, ['role_id' => $roleId, 'is_active' => true]);

        return $user;
    }
}
