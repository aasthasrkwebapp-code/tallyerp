<?php

namespace Tests\Feature\Payroll;

use App\Models\Account;
use App\Models\AccountGroup;
use App\Models\Employee;
use App\Models\EmployeePayHead;
use App\Models\PayHead;
use App\Models\PayrollRun;
use App\Services\Payroll\PayrollFiles;
use App\Services\Payroll\PayrollService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * October 2026, the same two employees as PayrollTest:
 *   Asha  gross 19,800, basic 12,000, PF 1,440 + 1,440, ESI 149 + 644, net 18,011
 *   Ravi  joined 16 Oct (16 of 31 days), gross 25,806.45, basic earned 15,483.87,
 *         PF on the 15,000 ceiling 1,800 + 1,800, no ESI, net 21,306.45
 */
class PayrollFilesTest extends TestCase
{
    use RefreshDatabase;

    protected $owner;
    protected $company;
    protected Employee $asha;
    protected Employee $ravi;
    protected Account $bank;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->owner, $this->company] = $this->createCompanyWithOwner(['state' => 'Maharashtra']);
        $this->company->update(['settings' => ['allow_future_dated_vouchers' => true]]);
        $this->bank = Account::create(['name' => 'HDFC Bank', 'account_group_id' => AccountGroup::idFor('bank_accounts'), 'account_type' => 'bank']);

        $this->asha = $this->employee('EMP001', 'Asha Patil', '2025-01-01', ['basic' => '12000', 'hra' => null, 'special' => '3000', 'pf_employee' => null, 'esi_employee' => null, 'pt' => null, 'pf_employer' => null, 'esi_employer' => null],
            ['uan' => '100200300400', 'esi_number' => '3100123456', 'bank_name' => 'HDFC Bank', 'bank_account' => '50100012345678', 'ifsc' => 'HDFC0001234']);
        $this->ravi = $this->employee('EMP002', 'Ravi Kumar', '2026-10-16', ['basic' => '30000', 'hra' => null, 'special' => '8000', 'pf_employee' => null, 'esi_employee' => null, 'pt' => null, 'tds' => '2500', 'pf_employer' => null, 'esi_employer' => null],
            ['uan' => '100200300500']);
    }

    protected function employee(string $code, string $name, string $joined, array $structure, array $extra = []): Employee
    {
        $employee = Employee::create(['code' => $code, 'name' => $name, 'date_of_joining' => $joined] + $extra);
        foreach ($structure as $key => $amount) {
            EmployeePayHead::create(['employee_id' => $employee->id, 'pay_head_id' => PayHead::where('system_key', $key)->value('id'), 'amount' => $amount]);
        }

        return $employee->fresh();
    }

    protected function posted(): PayrollRun
    {
        $service = app(PayrollService::class);

        return $service->post($service->prepare('2026-10', $this->owner), $this->owner);
    }

    public function test_bank_transfer_list_leaves_out_missing_bank_details(): void
    {
        $bank = app(PayrollFiles::class)->bankTransfer($this->posted());
        $this->assertSame([['EMP001', '50100012345678', 'HDFC0001234', 1801100]], $bank['rows']->map(fn ($r) => [$r->code, $r->account, $r->ifsc, $r->amount])->all());
        $this->assertSame(1801100, $bank['total']);
        $this->assertSame([['EMP002', 'No bank account number', 2130645]], $bank['skipped']->map(fn ($s) => [$s->payslip->employee_code, $s->reason, $s->amount])->all());

        $this->ravi->update(['bank_account' => '1234567890', 'ifsc' => 'BAD']);
        $this->assertSame('IFSC is not valid', app(PayrollFiles::class)->bankTransfer(PayrollRun::first())['skipped'][0]->reason);
    }

    public function test_ecr_rows_and_text(): void
    {
        $run = $this->posted();
        $ecr = app(PayrollFiles::class)->ecr($run);
        $rows = $ecr['rows']->keyBy('uan');

        // Asha: EPS 8.33% of 12,000 = 999.60 → 1,000; employer balance 440.
        $a = $rows['100200300400'];
        $this->assertSame([19800, 12000, 12000, 12000, 1440, 1000, 440, 0], [$a->gross, $a->epf_wages, $a->eps_wages, $a->edli_wages, $a->epf, $a->eps, $a->diff, $a->ncp]);
        // Ravi: wages capped at 15,000, EPS 1,250, 15 unpaid days.
        $r = $rows['100200300500'];
        $this->assertSame([25806, 15000, 15000, 15000, 1800, 1250, 550, 15], [$r->gross, $r->epf_wages, $r->eps_wages, $r->edli_wages, $r->epf, $r->eps, $r->diff, $r->ncp]);
        $this->assertSame(3240, $ecr['totals']['epf']);

        $this->assertSame(
            "100200300400#~#ASHA PATIL#~#19800#~#12000#~#12000#~#12000#~#1440#~#1000#~#440#~#0#~#0\n".
            "100200300500#~#RAVI KUMAR#~#25806#~#15000#~#15000#~#15000#~#1800#~#1250#~#550#~#15#~#0\n",
            app(PayrollFiles::class)->ecrText($ecr)
        );

        // From the month a member turns 58 there is no EPS: all of the employer's share goes to EPF.
        $this->ravi->update(['date_of_birth' => '1968-10-20']);
        $r = app(PayrollFiles::class)->ecr($run)['rows']->firstWhere('uan', '100200300500');
        $this->assertSame([0, 0, 1800], [$r->eps_wages, $r->eps, $r->diff]);

        // No UAN: left out with the reason.
        $this->asha->update(['uan' => null]);
        $ecr = app(PayrollFiles::class)->ecr($run);
        $this->assertSame(['No UAN'], $ecr['skipped']->pluck('reason')->all());
        $this->assertCount(1, $ecr['rows']);
    }

    public function test_esi_contribution_rows(): void
    {
        $esi = app(PayrollFiles::class)->esi($this->posted());
        $this->assertSame([['3100123456', 'Asha Patil', 31, 19800, 14900, 64400, 0]], $esi['rows']->map(fn ($r) => [$r->ip, $r->name, $r->days, $r->wages, $r->employee, $r->employer, $r->reason])->all());
        $this->assertTrue($esi['skipped']->isEmpty()); // Ravi is above the ESI wage limit
    }

    public function test_salary_payment_voucher_and_cancel(): void
    {
        $service = app(PayrollService::class);
        $draft = $service->prepare('2026-10', $this->owner);
        try {
            $service->pay($draft, ['date' => '2026-10-31', 'paid_account_id' => $this->bank->id], $this->owner);
            $this->fail('A draft payroll was paid.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('run', $e->errors());
        }
        $run = $service->post($draft, $this->owner);

        try {
            $service->pay($run, ['date' => '2026-10-31', 'paid_account_id' => Account::idFor('salaries')], $this->owner);
            $this->fail('Paid from an expense ledger.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('paid_account_id', $e->errors());
        }

        $run = $service->pay($run, ['date' => '2026-11-01', 'paid_account_id' => $this->bank->id], $this->owner);
        $voucher = $run->paymentVoucher;
        $this->assertTrue($run->isPaid());
        $this->assertSame('payment', $voucher->type->base_type);
        $entries = $voucher->entries->mapWithKeys(fn ($e) => [$e->account_id => ((float) $e->debit > 0 ? 'Dr '.$e->debit : 'Cr '.$e->credit)])->all();
        $this->assertSame([Account::idFor('salary_payable') => 'Dr 39317.45', $this->bank->id => 'Cr 39317.45'], $entries);

        $this->expectException(ValidationException::class);
        try {
            $service->pay($run, ['date' => '2026-11-01', 'paid_account_id' => $this->bank->id], $this->owner);
        } finally {
            // Cancelling the payroll cancels the payment too.
            $service->cancel($run, $this->owner, 'Wrong month');
            $this->assertTrue($voucher->fresh()->isCancelled());
        }
    }

    public function test_screens_downloads_and_permissions(): void
    {
        $run = app(PayrollService::class)->prepare('2026-10', $this->owner);
        $this->actingAs($this->owner)->get(route('payroll-runs.files', $run))->assertOk()->assertSee('Post the payroll first');
        $this->get(route('payroll-runs.ecr', $run))->assertRedirect(route('payroll-runs.files', $run));

        $run = app(PayrollService::class)->post($run, $this->owner);
        $this->get(route('payroll-runs.show', $run))->assertSee('Payment &amp; files', false)->assertSee('salary not paid yet');
        $this->get(route('payroll-runs.files', $run))->assertOk()
            ->assertSee('50100012345678')->assertSee('ASHA PATIL')->assertSee('3100123456')
            ->assertSee('Left out of the file')->assertSee('No bank account number');

        $ecr = $this->get(route('payroll-runs.ecr', $run));
        $ecr->assertOk()->assertHeader('content-disposition', 'attachment; filename=ECR_2026-10.txt');
        $this->assertStringStartsWith('100200300400#~#ASHA PATIL#~#', $ecr->streamedContent());

        $bank = $this->get(route('payroll-runs.bank-file', $run));
        $bank->assertOk();
        $this->assertStringContainsString('50100012345678', $bank->streamedContent());
        $this->assertStringContainsString('Salary Oct 2026', $bank->streamedContent());
        $this->get(route('payroll-runs.esi-file', $run))->assertOk()->assertHeader('content-disposition', 'attachment; filename=esi-contribution-2026-10.xlsx');

        $this->post(route('payroll-runs.pay', $run), ['date' => '2026-10-31', 'paid_account_id' => $this->bank->id])
            ->assertRedirect(route('payroll-runs.files', $run));
        $this->get(route('payroll-runs.files', $run))->assertSee('Paid by payment voucher');

        $viewer = $this->addMember($this->company, 'viewer');
        $this->actingAs($viewer)->get(route('payroll-runs.files', $run))->assertOk()->assertDontSee('Record payment');
        $this->actingAs($viewer)->get(route('payroll-runs.ecr', $run))->assertForbidden();
        $this->actingAs($viewer)->post(route('payroll-runs.pay', $run), ['date' => '2026-10-31', 'paid_account_id' => $this->bank->id])->assertForbidden();
    }
}
