<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CompanyDeletionTest extends TestCase
{
    use RefreshDatabase;

    public function test_company_with_vouchers_stock_and_payroll_can_be_deleted(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::firstOrFail();
        $employee = $company->employees()->create(['name' => 'Asha', 'basic' => 12000]);
        $this->actingAs($company->user)
            ->post(route('companies.payroll.store', $company), ['month' => $company->books_start_year.'-06', 'days' => [$employee->id => 30]])
            ->assertSessionHasNoErrors();

        $this->actingAs($company->user)
            ->delete(route('companies.destroy', $company))
            ->assertRedirect(route('companies.index'));

        $this->assertModelMissing($company);
        foreach (['vouchers', 'voucher_entries', 'voucher_gst_lines', 'stock_movements', 'stock_items', 'chart_of_accounts', 'payslips', 'employees', 'units'] as $table) {
            $this->assertSame(0, DB::table($table)->count(), $table.' not emptied');
        }
    }

    public function test_user_with_companies_can_delete_their_account(): void
    {
        $this->seed(DemoSeeder::class);
        $user = User::where('email', 'demo@example.com')->firstOrFail();

        $this->actingAs($user)
            ->delete(route('profile.destroy'), ['password' => 'password'])
            ->assertRedirect('/');

        $this->assertModelMissing($user);
        $this->assertSame(0, Company::count());
    }
}
