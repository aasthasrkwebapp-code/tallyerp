<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * June 2026 (30 days):
 *   Asha: 12,000 + 4,000 + 2,000 = 18,000, full month.
 *     PF 1,440 · ESI 135 (employer 585) · PT 200 → net 16,225
 *   Vikram: 30,000 + 15,000 = 45,000, 15 days → gross 22,500.
 *     PF 1,800 (capped) · no ESI (monthly gross over 21,000) · PT 200 → net 20,500
 */
class PayrollTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected Employee $asha;

    protected Employee $vikram;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = User::factory()->create()->companies()->create([
            'name' => 'Acme Traders', 'financial_year' => '2026-27', 'currency' => 'INR',
        ]);

        $this->asha = $this->company->employees()->create([
            'name' => 'Asha', 'basic' => 12000, 'hra' => 4000, 'other_allowances' => 2000,
        ]);
        $this->vikram = $this->company->employees()->create([
            'name' => 'Vikram', 'basic' => 30000, 'hra' => 15000, 'other_allowances' => 0,
        ]);
    }

    protected function runPayroll(array $days = null, string $month = '2026-06')
    {
        return $this->actingAs($this->company->user)
            ->post(route('companies.payroll.store', $this->company), [
                'month' => $month,
                'days' => $days ?? [$this->asha->id => 30, $this->vikram->id => 15],
            ]);
    }

    public function test_payslips_are_calculated(): void
    {
        $this->runPayroll()->assertSessionHasNoErrors();

        $run = $this->company->payrollRuns()->firstOrFail();
        $asha = $run->payslips()->where('employee_id', $this->asha->id)->firstOrFail();
        $vikram = $run->payslips()->where('employee_id', $this->vikram->id)->firstOrFail();

        $this->assertSame(['18000.00', '1440.00', '135.00', '200.00', '16225.00', '585.00'],
            [$asha->gross, $asha->pf_employee, $asha->esi_employee, $asha->professional_tax, $asha->net_pay, $asha->esi_employer]);

        $this->assertSame(['22500.00', '1800.00', '0.00', '20500.00', '1800.00'],
            [$vikram->gross, $vikram->pf_employee, $vikram->esi_employee, $vikram->net_pay, $vikram->pf_employer]);
    }

    public function test_salary_journal_is_posted_and_balanced(): void
    {
        $this->runPayroll();

        $voucher = $this->company->payrollRuns()->firstOrFail()->voucher;
        $this->assertSame('2026-06-30', $voucher->date->toDateString());

        $byCode = $voucher->entries()->with('account')->get()->keyBy(fn ($e) => $e->account->code);
        $this->assertSame('40500.00', $byCode['PAY_SALARIES']->debit);
        $this->assertSame('3825.00', $byCode['PAY_EMPLOYER_CONTRIB']->debit);
        $this->assertSame('36725.00', $byCode['PAY_SALARY_PAYABLE']->credit);
        $this->assertSame('6480.00', $byCode['PAY_PF_PAYABLE']->credit);
        $this->assertSame('720.00', $byCode['PAY_ESI_PAYABLE']->credit);
        $this->assertSame('400.00', $byCode['PAY_PT_PAYABLE']->credit);

        $this->assertSame(
            round((float) $voucher->entries()->sum('debit'), 2),
            round((float) $voucher->entries()->sum('credit'), 2),
        );
    }

    public function test_register_and_payslip_pages(): void
    {
        $this->runPayroll();
        $run = $this->company->payrollRuns()->firstOrFail();
        $slip = $run->payslips()->where('employee_id', $this->asha->id)->firstOrFail();

        $this->actingAs($this->company->user)
            ->get(route('companies.payroll.show', [$this->company, $run]))
            ->assertOk()
            ->assertSee('Payroll Register')
            ->assertSee('16,225.00');

        $this->actingAs($this->company->user)
            ->get(route('companies.payroll.payslip', [$this->company, $run, $slip]))
            ->assertOk()
            ->assertSee('Payslip for June 2026')
            ->assertSee('16,225.00');

        $this->actingAs($this->company->user)
            ->get(route('companies.payroll.index', $this->company))
            ->assertOk()
            ->assertSee('June 2026');
    }

    public function test_same_month_cannot_run_twice_and_month_must_be_in_year(): void
    {
        $this->runPayroll()->assertSessionHasNoErrors();
        $this->runPayroll()->assertSessionHasErrors('month');
        $this->runPayroll(null, '2027-05')->assertSessionHasErrors('month');
    }

    public function test_zero_days_skips_employee_and_all_zero_is_rejected(): void
    {
        $this->runPayroll([$this->asha->id => 30, $this->vikram->id => 0])->assertSessionHasNoErrors();
        $this->assertSame(1, $this->company->payrollRuns()->firstOrFail()->payslips()->count());

        $this->runPayroll([$this->asha->id => 0], '2026-07')->assertSessionHasErrors('days');
    }

    public function test_deleting_run_removes_journal_and_frees_employee(): void
    {
        $this->runPayroll();
        $run = $this->company->payrollRuns()->firstOrFail();
        $voucherId = $run->voucher_id;

        // Employee with payslips can't be deleted.
        $this->actingAs($this->company->user)
            ->delete(route('companies.employees.destroy', [$this->company, $this->asha]))
            ->assertSessionHas('error');

        $this->actingAs($this->company->user)
            ->delete(route('companies.payroll.destroy', [$this->company, $run]))
            ->assertRedirect(route('companies.payroll.index', $this->company));

        $this->assertDatabaseMissing('vouchers', ['id' => $voucherId]);
        $this->assertDatabaseCount('payslips', 0);

        $this->actingAs($this->company->user)
            ->delete(route('companies.employees.destroy', [$this->company, $this->asha]))
            ->assertSessionHas('success');
    }

    public function test_employee_form_saves_flags(): void
    {
        $this->actingAs($this->company->user)
            ->post(route('companies.employees.store', $this->company), [
                'name' => 'Meera', 'code' => 'E003', 'basic' => '25000', 'pan' => 'abcde1234f',
                'pf_applicable' => '1', 'esi_applicable' => '0', 'pt_applicable' => '1', 'is_active' => '1',
            ])
            ->assertRedirect(route('companies.employees.index', $this->company));

        $meera = $this->company->employees()->where('code', 'E003')->firstOrFail();
        $this->assertTrue($meera->pf_applicable);
        $this->assertFalse($meera->esi_applicable);
        $this->assertSame('ABCDE1234F', $meera->pan);

        $this->actingAs($this->company->user)
            ->get(route('companies.employees.index', $this->company))
            ->assertOk()
            ->assertSee('Meera');
    }

    public function test_other_company_cannot_see_payroll(): void
    {
        $this->runPayroll();
        $run = $this->company->payrollRuns()->firstOrFail();
        $other = User::factory()->create()->companies()->create([
            'name' => 'Other', 'financial_year' => '2026-27', 'currency' => 'INR',
        ]);

        $this->actingAs($other->user)
            ->get(route('companies.payroll.show', [$other, $run]))
            ->assertNotFound();

        $this->actingAs($other->user)
            ->get(route('companies.payroll.show', [$this->company, $run]))
            ->assertForbidden();
    }
}
