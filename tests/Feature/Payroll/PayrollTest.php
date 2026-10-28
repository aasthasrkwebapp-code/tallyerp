<?php

namespace Tests\Feature\Payroll;

use App\Models\Account;
use App\Models\Employee;
use App\Models\EmployeePayHead;
use App\Models\PayHead;
use App\Models\PayrollRun;
use App\Models\Voucher;
use App\Services\Payroll\PayrollCalculator;
use App\Services\Payroll\PayrollService;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * October 2026 (31 days), company in Maharashtra.
 *
 *   Asha  basic 12,000 + HRA 40% 4,800 + special 3,000 = 19,800 (ESI covered)
 *         PF 1,440 · ESI 0.75% 148.50 → 149 · PT 200 · net 18,011
 *         employer PF 1,440 · ESI 3.25% 643.50 → 644
 *   Ravi  basic 30,000 + HRA 12,000 + special 8,000, joined 16 Oct (16 of 31 days)
 *         earned 15,483.87 + 6,193.55 + 4,129.03 = 25,806.45
 *         PF on the 15,000 ceiling 1,800 · no ESI · PT 200 · TDS 2,500 · net 21,306.45
 */
class PayrollTest extends TestCase
{
    use RefreshDatabase;

    protected $owner;
    protected $company;
    protected Employee $asha;
    protected Employee $ravi;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->owner, $this->company] = $this->createCompanyWithOwner(['state' => 'Maharashtra']);
        $this->company->update(['settings' => ['allow_future_dated_vouchers' => true]]);

        $this->asha = $this->employee('EMP001', 'Asha Patil', '2025-01-01', ['basic' => '12000', 'hra' => null, 'special' => '3000', 'pf_employee' => null, 'esi_employee' => null, 'pt' => null, 'pf_employer' => null, 'esi_employer' => null]);
        $this->ravi = $this->employee('EMP002', 'Ravi Kumar', '2026-10-16', ['basic' => '30000', 'hra' => null, 'special' => '8000', 'pf_employee' => null, 'esi_employee' => null, 'pt' => null, 'tds' => '2500', 'pf_employer' => null, 'esi_employer' => null]);
    }

    protected function employee(string $code, string $name, string $joined, array $structure): Employee
    {
        $employee = Employee::create(['code' => $code, 'name' => $name, 'date_of_joining' => $joined]);
        foreach ($structure as $key => $amount) {
            EmployeePayHead::create(['employee_id' => $employee->id, 'pay_head_id' => PayHead::where('system_key', $key)->value('id'), 'amount' => $amount]);
        }

        return $employee->fresh();
    }

    protected function amounts($slip): array
    {
        return $slip->lines->mapWithKeys(fn ($l) => [PayHead::find($l->pay_head_id)->system_key => $l->amount])->all();
    }

    public function test_default_pay_heads_and_ledgers_exist(): void
    {
        $this->assertSame(11, PayHead::count());
        $this->assertNotNull(Account::idFor('salary_payable'));
        $this->assertSame(Account::idFor('pf_payable'), PayHead::where('system_key', 'pf_employer')->value('payable_account_id'));
    }

    public function test_payslips_follow_statutory_rules(): void
    {
        $run = app(PayrollService::class)->prepare('2026-10', $this->owner);
        $asha = $run->payslips->firstWhere('employee_id', $this->asha->id);
        $ravi = $run->payslips->firstWhere('employee_id', $this->ravi->id);

        $this->assertSame('31.0', $asha->paid_days);
        $this->assertSame(['basic' => '12000.00', 'hra' => '4800.00', 'special' => '3000.00', 'pf_employee' => '1440.00', 'esi_employee' => '149.00', 'pt' => '200.00', 'pf_employer' => '1440.00', 'esi_employer' => '644.00'], $this->amounts($asha));
        $this->assertSame(['19800.00', '1789.00', '18011.00', '2084.00'], [$asha->gross, $asha->deductions, $asha->net, $asha->employer_contributions]);

        $this->assertSame('16.0', $ravi->paid_days); // joined on the 16th
        $this->assertSame(['basic' => '15483.87', 'hra' => '6193.55', 'special' => '4129.03', 'pf_employee' => '1800.00', 'esi_employee' => '0.00', 'pt' => '200.00', 'tds' => '2500.00', 'pf_employer' => '1800.00', 'esi_employer' => '0.00'], $this->amounts($ravi));
        $this->assertSame(['25806.45', '21306.45'], [$ravi->gross, $ravi->net]);

        $this->assertSame(['45606.45', '6289.00', '39317.45', '3884.00'], [$run->gross_total, $run->deduction_total, $run->net_total, $run->employer_total]);

        // Only one payroll per month.
        $this->expectException(ValidationException::class);
        app(PayrollService::class)->prepare('2026-10', $this->owner);
    }

    public function test_paid_days_reduce_prorated_earnings(): void
    {
        $service = app(PayrollService::class);
        $run = $service->prepare('2026-10', $this->owner);
        $run = $service->recalculate($run, [$this->asha->id => '15.5']);
        $asha = $run->payslips->firstWhere('employee_id', $this->asha->id);

        // 12,000 × 15.5 / 31 = 6,000; HRA 2,400; special 1,500; PF 720; ESI on 9,900 = 74.25 → 75; PT 175 (7,501–10,000 slab)
        $this->assertSame(['6000.00', '2400.00', '1500.00', '720.00', '75.00', '175.00'], array_values(array_intersect_key($this->amounts($asha), array_flip(['basic', 'hra', 'special', 'pf_employee', 'esi_employee', 'pt']))));

        $this->expectException(ValidationException::class);
        $service->recalculate($run, [$this->asha->id => '32']);
    }

    public function test_professional_tax_slabs(): void
    {
        $calc = app(PayrollCalculator::class);
        $this->assertSame(0, $calc->professionalTax(750000, 10, 'Maharashtra'));
        $this->assertSame(17500, $calc->professionalTax(900000, 10, 'Maharashtra'));
        $this->assertSame(30000, $calc->professionalTax(2000000, 2, 'Maharashtra')); // February
        $this->assertSame(20000, $calc->professionalTax(3000000, 10, 'Karnataka'));
        $this->assertSame(0, $calc->professionalTax(3000000, 10, 'Delhi'));          // no PT
    }

    public function test_posting_creates_a_balanced_salary_journal_and_cancel_reverses_it(): void
    {
        $service = app(PayrollService::class);
        $run = $service->post($service->prepare('2026-10', $this->owner), $this->owner);

        $this->assertSame('posted', $run->status);
        $voucher = $run->voucher;
        $this->assertSame(Voucher::POSTED, $voucher->status);
        $this->assertSame(PayrollRun::class, $voucher->source_type);
        $lines = $voucher->entries()->with('account')->get()->mapWithKeys(fn ($e) => [$e->account->system_key => $e->side().' '.Money::fromPaise($e->amountPaise())])->all();
        $this->assertSame([
            'salaries' => 'Dr 45606.45',
            'employer_pf' => 'Dr 3240.00',
            'employer_esi' => 'Dr 644.00',
            'pf_payable' => 'Cr 6480.00',
            'esi_payable' => 'Cr 793.00',
            'pt_payable' => 'Cr 400.00',
            'tds_salary' => 'Cr 2500.00',
            'salary_payable' => 'Cr 39317.45',
        ], $lines);

        // Posted payroll cannot be recalculated.
        try {
            $service->recalculate($run, []);
            $this->fail('Posted payroll was recalculated.');
        } catch (ValidationException) {
        }

        $service->cancel($run, $this->owner, 'Wrong attendance');
        $this->assertSame('cancelled', $run->fresh()->status);
        $this->assertTrue($voucher->fresh()->isCancelled());

        // The month can be prepared again after cancelling.
        $this->assertSame('draft', $service->prepare('2026-10', $this->owner)->status);
    }

    public function test_leavers_and_future_joiners_are_left_out(): void
    {
        $this->asha->update(['date_of_leaving' => '2026-09-30']);
        $run = app(PayrollService::class)->prepare('2026-10', $this->owner);
        $this->assertSame([$this->ravi->id], $run->payslips->pluck('employee_id')->all());

        // Ravi joined in October, so September has only Asha.
        $september = app(PayrollService::class)->prepare('2026-09', $this->owner);
        $this->assertSame([$this->asha->id], $september->payslips->pluck('employee_id')->all());
    }

    public function test_screens_and_forms(): void
    {
        $this->actingAs($this->owner);

        $heads = PayHead::all()->keyBy('system_key');
        $this->post(route('employees.store'), [
            'code' => 'EMP003', 'name' => 'Meera Shah', 'date_of_joining' => '2026-04-01', 'pan' => 'abcde1234f', 'ifsc' => 'hdfc0001234',
            'pf_applicable' => 1, 'esi_applicable' => 1, 'pt_applicable' => 1, 'is_active' => 1,
            'heads' => [$heads['basic']->id => ['on' => 1, 'amount' => '20000'], $heads['pf_employee']->id => ['on' => 1], $heads['special']->id => ['on' => 0, 'amount' => '']],
        ])->assertRedirect();
        $meera = Employee::where('code', 'EMP003')->first();
        $this->assertSame('ABCDE1234F', $meera->pan);
        $this->assertSame(2, $meera->payHeads()->count());

        // Basic is required in a structure; flat heads need an amount.
        $this->post(route('employees.store'), ['code' => 'EMP004', 'name' => 'X', 'date_of_joining' => '2026-04-01', 'heads' => [$heads['special']->id => ['on' => 1]]])
            ->assertSessionHasErrors(['heads', 'heads.'.$heads['special']->id.'.amount']);

        // Pay head: a deduction must post to a liability ledger.
        $this->post(route('pay-heads.store'), ['name' => 'Canteen', 'type' => 'deduction', 'calculation' => 'flat', 'account_id' => Account::idFor('salaries')])->assertSessionHasErrors('account_id');
        $this->post(route('pay-heads.store'), ['name' => 'Canteen', 'type' => 'deduction', 'calculation' => 'flat', 'account_id' => Account::idFor('salary_payable'), 'is_active' => 1])->assertRedirect();
        $this->delete(route('pay-heads.destroy', $heads['basic']))->assertSessionHas('error');

        $this->post(route('payroll-runs.store'), ['month' => '2026-10'])->assertRedirect();
        $run = PayrollRun::first();
        $this->get(route('payroll-runs.show', $run))->assertOk()->assertSee('Asha Patil')->assertSee('57,517.45'); // with Meera: 20,000 − PF 1,800
        $this->get(route('payroll-runs.show', [$run, 'export' => 'csv']))->assertOk();
        $this->put(route('payroll-runs.update', $run), ['paid_days' => [$this->asha->id => '31']])->assertRedirect();
        $slip = $run->payslips()->where('employee_id', $this->asha->id)->first();
        $this->get(route('payroll-runs.payslip', [$run, $slip]))->assertOk()->assertSee('18,011.00');
        $this->post(route('payroll-runs.post', $run), ['date' => '2026-10-31'])->assertRedirect();
        $this->assertSame('posted', $run->fresh()->status);
        $this->get(route('vouchers.show', $run->fresh()->voucher_id))->assertOk()->assertSee('payroll for October 2026');

        foreach (['employees.index', 'employees.create', 'pay-heads.index', 'payroll-runs.index'] as $route) {
            $this->get(route($route))->assertOk();
        }
        $this->get(route('employees.show', $this->asha))->assertOk()->assertSee('October 2026');
        $this->get(route('employees.edit', $this->asha))->assertOk();
        $this->delete(route('employees.destroy', $this->asha))->assertSessionHas('error'); // has payslips
    }

    public function test_permissions(): void
    {
        $viewer = $this->addMember($this->company, 'viewer');
        $this->actingAs($viewer)->get(route('employees.index'))->assertOk();
        $this->post(route('payroll-runs.store'), ['month' => '2026-10'])->assertForbidden();

        $sales = $this->addMember($this->company, 'sales');
        $this->actingAs($sales)->get(route('employees.index'))->assertForbidden();

        $payroll = $this->addMember($this->company, 'employee');
        $this->actingAs($payroll)->post(route('payroll-runs.store'), ['month' => '2026-10'])->assertRedirect();
    }
}
