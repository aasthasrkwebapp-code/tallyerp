<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuditLogTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesSeeder::class);

        $this->owner = User::factory()->create(['name' => 'Priya']);
        $this->actingAs($this->owner)->post(route('companies.store'), [
            'name' => 'Acme Traders', 'financial_year' => '2026-27', 'currency' => 'INR',
        ])->assertSessionHasNoErrors();

        $this->company = Company::firstOrFail();
    }

    public function test_company_creation_is_logged_without_default_groups(): void
    {
        $this->assertSame(1, AuditLog::count());
        $log = AuditLog::firstOrFail();
        $this->assertSame(['created', 'Company', $this->owner->id], [$log->action, $log->subject_type, $log->user_id]);
    }

    public function test_ledger_and_voucher_changes_are_logged_with_user_and_diff(): void
    {
        $cashGroup = $this->company->accountGroups()->where('code', 'CASH_IN_HAND')->value('id');
        $rentGroup = $this->company->accountGroups()->where('code', 'INDIRECT_EXPENSES')->value('id');

        $this->actingAs($this->owner)->post(route('companies.accounts.store', $this->company), [
            'name' => 'Cash', 'account_group_id' => $cashGroup, 'opening_balance_type' => 'dr', 'is_active' => '1',
        ]);
        $this->actingAs($this->owner)->post(route('companies.accounts.store', $this->company), [
            'name' => 'Rent', 'account_group_id' => $rentGroup, 'opening_balance_type' => 'dr', 'is_active' => '1',
        ]);
        $cash = $this->company->accounts()->where('name', 'Cash')->firstOrFail();
        $rent = $this->company->accounts()->where('name', 'Rent')->firstOrFail();

        $this->actingAs($this->owner)->post(route('companies.vouchers.store', $this->company), [
            'type' => 'payment', 'date' => '2026-06-01',
            'entries' => [['account_id' => $rent->id, 'debit' => 500], ['account_id' => $cash->id, 'credit' => 500]],
        ])->assertSessionHasNoErrors();
        $voucher = $this->company->vouchers()->firstOrFail();

        $this->actingAs($this->owner)->put(route('companies.vouchers.update', [$this->company, $voucher]), [
            'date' => '2026-06-01',
            'entries' => [['account_id' => $rent->id, 'debit' => 700], ['account_id' => $cash->id, 'credit' => 700]],
        ])->assertSessionHasNoErrors();

        $this->actingAs($this->owner)->delete(route('companies.vouchers.destroy', [$this->company, $voucher]));

        $logs = $this->company->auditLogs()->where('subject_type', 'Voucher')->orderBy('id')->get();
        $this->assertSame(['created', 'updated', 'deleted'], $logs->pluck('action')->all());
        $this->assertSame('Payment #1 (500.00)', $logs[0]->summary);
        $this->assertEquals(['from' => '500.00', 'to' => 700], $logs[1]->changes['amount']);

        $this->assertTrue($this->company->auditLogs()->where('summary', 'Ledger Rent')->exists());
    }

    public function test_member_changes_are_logged(): void
    {
        $colleague = User::factory()->create(['name' => 'Ravi']);

        $this->actingAs($this->owner)->post(route('companies.members.store', $this->company), [
            'email' => $colleague->email, 'role_id' => Role::where('name', 'viewer')->value('id'),
        ]);

        $this->assertTrue($this->company->auditLogs()->where('summary', 'Gave Ravi access as viewer')->exists());
    }

    public function test_audit_page_for_admins_only(): void
    {
        $this->actingAs($this->owner)
            ->get(route('companies.audit', $this->company))
            ->assertOk()
            ->assertSee('Priya')
            ->assertSee('Company Acme Traders');

        $viewer = User::factory()->create();
        $this->company->members()->attach($viewer->id, ['role_id' => Role::where('name', 'accountant')->value('id')]);

        $this->actingAs($viewer)->get(route('companies.audit', $this->company))->assertForbidden();
    }

    public function test_audit_filters(): void
    {
        $this->actingAs($this->owner)->put(route('companies.update', $this->company), [
            'name' => 'Acme Traders Pvt Ltd', 'financial_year' => '2026-27', 'currency' => 'INR',
        ])->assertSessionHasNoErrors();

        $response = $this->actingAs($this->owner)
            ->get(route('companies.audit', [$this->company, 'action' => 'updated']))
            ->assertOk();

        $this->assertCount(1, $response->viewData('logs'));
        $response->assertSee('Acme Traders Pvt Ltd');
    }
}
