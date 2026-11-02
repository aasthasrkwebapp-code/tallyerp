<?php

namespace Tests\Feature\Company;

use App\Models\AccountingPeriod;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\FinancialYear;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CompanyManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_creating_a_company_sets_up_owner_financial_year_and_periods(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/companies', [
            'name' => 'Sharma Logistics',
            'gstin' => '27ABCDE1234F1Z5',
            'pan' => 'ABCDE1234F',
            'state' => 'Maharashtra',
            'pincode' => '411001',
            'financial_year' => '2026-27',
            'currency' => 'INR',
        ]);

        $response->assertRedirect(route('dashboard'));
        $company = Company::where('name', 'Sharma Logistics')->firstOrFail();

        $this->assertTrue($user->fresh()->belongsToCompany($company));
        $this->assertSame('owner', $user->fresh()->roleFor($company)->name);
        $this->assertSame('2026-04-01', $company->books_begin_date->toDateString());

        $year = FinancialYear::withoutGlobalScopes()->where('company_id', $company->id)->firstOrFail();
        $this->assertSame('2026-27', $year->name);
        $this->assertSame('2027-03-31', $year->end_date->toDateString());
        $this->assertTrue($year->is_current);
        $this->assertSame(12, AccountingPeriod::withoutGlobalScopes()->where('financial_year_id', $year->id)->count());
        $this->assertSame($company->id, session('current_company_id'));
    }

    public function test_company_validation_rejects_bad_statutory_details(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/companies', [
            'name' => 'Bad Co',
            'gstin' => '27ABCDE1234F1Z5',
            'pan' => 'ZZZZZ9999Z',          // does not match the PAN inside the GSTIN
            'state' => 'Karnataka',          // GSTIN state code 27 is Maharashtra
            'pincode' => '12',
            'financial_year' => '2026-27',
            'currency' => 'INR',
        ])->assertSessionHasErrors(['gstin', 'pincode']);

        $this->assertSame(0, Company::count());
    }

    public function test_books_begin_date_must_fall_inside_the_financial_year(): void
    {
        $this->actingAs(User::factory()->create())->post('/companies', [
            'name' => 'Early Co',
            'financial_year' => '2026-27',
            'books_begin_date' => '2025-12-01',
            'currency' => 'INR',
        ])->assertSessionHasErrors('books_begin_date');
    }

    public function test_user_only_sees_and_opens_their_own_companies(): void
    {
        [$owner, $mine] = $this->createCompanyWithOwner(['name' => 'Mine']);
        [, $theirs] = $this->createCompanyWithOwner(['name' => 'Theirs']);

        $this->actingAs($owner)->get('/companies')
            ->assertOk()->assertSee('Mine')->assertDontSee('Theirs');

        $this->actingAs($owner)->get("/companies/{$theirs->id}")->assertForbidden();
        $this->actingAs($owner)->post("/companies/{$theirs->id}/switch")->assertForbidden();
        $this->actingAs($owner)->put("/companies/{$theirs->id}", ['name' => 'Hacked', 'currency' => 'INR'])->assertForbidden();
        $this->assertSame('Theirs', $theirs->fresh()->name);
    }

    public function test_switching_company_changes_the_active_company(): void
    {
        $user = User::factory()->create();
        [, $a] = $this->createCompanyWithOwner(['name' => 'Alpha'], $user);
        [, $b] = $this->createCompanyWithOwner(['name' => 'Beta'], $user);

        $this->actingAs($user)->post("/companies/{$a->id}/switch")->assertRedirect(route('dashboard'));
        $this->assertSame($a->id, session('current_company_id'));

        $this->actingAs($user)->get('/dashboard')->assertOk()->assertSee('Alpha');
    }

    public function test_company_updates_are_audited_with_old_and_new_values(): void
    {
        [$owner, $company] = $this->createCompanyWithOwner();

        $this->actingAs($owner)->put('/settings', [
            'name' => 'Acme Traders Pvt Ltd',
            'state' => 'Maharashtra',
            'currency' => 'INR',
        ])->assertRedirect();

        $log = AuditLog::where('auditable_type', Company::class)->where('event', 'updated')->latest('id')->firstOrFail();
        $this->assertSame('Acme Traders', $log->old_values['name']);
        $this->assertSame('Acme Traders Pvt Ltd', $log->new_values['name']);
        $this->assertSame($owner->id, $log->user_id);
    }

    public function test_preferences_are_saved(): void
    {
        [$owner, $company] = $this->createCompanyWithOwner();

        $this->actingAs($owner)->put('/settings/preferences', [
            'enable_cost_centres' => '1',
            'invoice_terms' => 'Payment due in 30 days.',
        ])->assertRedirect();

        $company->refresh();
        $this->assertTrue($company->setting('enable_cost_centres'));
        $this->assertFalse($company->setting('maintain_bill_wise'));
        $this->assertSame('Payment due in 30 days.', $company->setting('invoice_terms'));
    }

    public function test_deleting_a_company_requires_typing_its_name(): void
    {
        [$owner, $company] = $this->createCompanyWithOwner();

        $this->actingAs($owner)->delete("/companies/{$company->id}", ['confirm_name' => 'wrong'])
            ->assertSessionHasErrors('confirm_name');
        $this->assertNotNull($company->fresh());

        $this->actingAs($owner)->delete("/companies/{$company->id}", ['confirm_name' => 'Acme Traders'])
            ->assertRedirect(route('companies.index'));
        $this->assertNull($company->fresh());
    }

    public function test_owner_cannot_delete_account_while_owning_a_company(): void
    {
        [$owner] = $this->createCompanyWithOwner();

        $this->actingAs($owner)->delete('/profile', ['password' => 'password'])
            ->assertSessionHasErrorsIn('userDeletion', 'password');
        $this->assertNotNull($owner->fresh());
    }
}
