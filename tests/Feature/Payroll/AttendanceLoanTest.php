<?php

namespace Tests\Feature\Payroll;

use App\Models\Account;
use App\Models\AttendanceDay;
use App\Models\AttendanceMonth;
use App\Models\Employee;
use App\Models\EmployeeLoan;
use App\Models\EmployeePayHead;
use App\Models\LeaveType;
use App\Models\PayHead;
use App\Models\PayrollRun;
use App\Models\Voucher;
use App\Services\Payroll\AttendanceService;
use App\Services\Payroll\LoanService;
use App\Services\Payroll\PayrollService;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * October 2026 (31 days), company in Maharashtra.
 *
 *   Asha  basic 12,000 + HRA 40% + special 3,000 = 19,800; full month: deductions 1,789, net 18,011
 *         overtime at ₹100 an hour
 *   Ravi  basic 30,000 + HRA + special 8,000, TDS 2,500, joined 16 Oct; net 21,306.45 for 16 days
 */
class AttendanceLoanTest extends TestCase
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

        $this->asha = $this->employee('EMP001', 'Asha Patil', '2025-01-01', ['basic' => '12000', 'hra' => null, 'special' => '3000', 'overtime' => '100', 'pf_employee' => null, 'esi_employee' => null, 'pt' => null, 'pf_employer' => null, 'esi_employer' => null]);
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

    protected function leave(string $code): int
    {
        return LeaveType::where('code', $code)->value('id');
    }

    protected function amounts($slip): array
    {
        return $slip->lines->mapWithKeys(fn ($l) => [PayHead::find($l->pay_head_id)->system_key => $l->amount])->all();
    }

    protected function loan(Employee $employee, string $amount, string $installment, string $from = '2026-10'): EmployeeLoan
    {
        return app(LoanService::class)->create([
            'employee_id' => $employee->id, 'kind' => 'advance', 'date' => '2026-10-16', 'amount' => $amount, 'installment' => $installment,
            'recover_from' => $from, 'paid_account_id' => Account::idFor('cash'),
        ], $this->owner);
    }

    public function test_defaults_are_seeded(): void
    {
        $this->assertSame(['CL', 'EL', 'LWP', 'SL'], LeaveType::orderBy('code')->pluck('code')->all());
        $this->assertFalse(LeaveType::where('code', 'LWP')->value('is_paid'));
        $this->assertSame('overtime', PayHead::where('system_key', 'overtime')->value('calculation'));
        $loanHead = PayHead::where('system_key', 'loan_recovery')->first();
        $this->assertSame(Account::idFor('salary_advances'), $loanHead->account_id);
        $this->assertSame('asset', Account::with('group')->find($loanHead->account_id)->group->nature);
    }

    public function test_paid_days_come_from_the_register(): void
    {
        $service = app(AttendanceService::class);
        $service->save('2026-10', [
            $this->asha->id => [
                '4' => 'week_off', '11' => 'week_off', '18' => 'week_off', '25' => 'week_off',
                '6' => 'absent', '7' => 'absent', '8' => 'half_day',
                '13' => 'leave:'.$this->leave('CL'), '14' => 'half_leave:'.$this->leave('SL'),
                '20' => 'leave:'.$this->leave('LWP'), '2' => 'holiday', '3' => '',
            ],
            // Ravi joined on the 16th: the 5th is ignored.
            $this->ravi->id => ['5' => 'absent', '20' => 'absent'],
        ], [$this->asha->id => '7.5']);

        $this->assertSame(12, AttendanceDay::count()); // blanks and days off the rolls are not stored
        $register = $service->register('2026-10')->keyBy(fn ($r) => $r->employee->id);
        // 31 − 2 absent − ½ − 1 day without pay; paid leave, offs and the holiday are paid.
        $this->assertSame('27.5', $register[$this->asha->id]->paid_days);
        $this->assertSame(['present' => '21', 'absent' => '2.5', 'leave' => '1.5', 'unpaid_leave' => '1', 'off' => '5'], $register[$this->asha->id]->summary);
        $this->assertSame([16, 31], $register[$this->ravi->id]->on_rolls);
        $this->assertSame('15', $register[$this->ravi->id]->paid_days);

        $run = app(PayrollService::class)->prepare('2026-10', $this->owner);
        $asha = $run->payslips->firstWhere('employee_id', $this->asha->id);
        $this->assertSame(['27.5', '7.50', true], [$asha->paid_days, $asha->overtime_hours, $asha->from_attendance]);
        $amounts = $this->amounts($asha);
        $this->assertSame(['10645.16', '4258.06', '2661.29', '750.00'], [$amounts['basic'], $amounts['hra'], $amounts['special'], $amounts['overtime']]);
        $this->assertSame('15.0', $run->payslips->firstWhere('employee_id', $this->ravi->id)->paid_days);

        // A manual change stops the payslip being "from attendance"; taking it again restores it.
        $service = app(PayrollService::class);
        $run = $service->recalculate($run, [$this->asha->id => '31']);
        $this->assertFalse($run->payslips->firstWhere('employee_id', $this->asha->id)->from_attendance);
        $this->assertSame('31.0', $run->payslips->firstWhere('employee_id', $this->asha->id)->paid_days);
        $run = $service->recalculate($run, [], fromAttendance: true);
        $this->assertSame('27.5', $run->payslips->firstWhere('employee_id', $this->asha->id)->paid_days);

        // Saving again replaces the month.
        app(AttendanceService::class)->save('2026-10', [$this->asha->id => ['6' => 'absent']], [$this->asha->id => '']);
        $this->assertSame(1, AttendanceDay::where('employee_id', $this->asha->id)->count());
        $this->assertSame('0.00', AttendanceMonth::where('employee_id', $this->asha->id)->value('overtime_hours'));

        $this->expectException(ValidationException::class);
        app(AttendanceService::class)->save('2026-10', [$this->asha->id => ['6' => 'leave']], []); // leave needs a type
    }

    public function test_overtime_without_marked_days_keeps_full_month(): void
    {
        AttendanceMonth::create(['employee_id' => $this->asha->id, 'month' => '2026-10', 'overtime_hours' => '10']);
        $slip = app(PayrollService::class)->prepare('2026-10', $this->owner)->payslips->firstWhere('employee_id', $this->asha->id);

        $this->assertSame(['31.0', false], [$slip->paid_days, $slip->from_attendance]);
        $this->assertSame('1000.00', $this->amounts($slip)['overtime']);
        $this->assertSame('20800.00', $slip->gross);
    }

    public function test_leave_register(): void
    {
        app(AttendanceService::class)->save('2026-10', [$this->asha->id => [
            '13' => 'leave:'.$this->leave('CL'), '14' => 'half_leave:'.$this->leave('CL'), '20' => 'leave:'.$this->leave('LWP'),
        ]], []);
        $report = app(AttendanceService::class)->leaveRegister('2026-10-31');

        $this->assertSame(['2026-04-01', '2027-03-31'], [$report['from'], $report['to']]);
        $asha = $report['rows']->firstWhere('employee.id', $this->asha->id);
        $this->assertSame(['allowed' => 12.0, 'taken' => 1.5, 'balance' => 10.5], $asha->types[$this->leave('CL')]);
        $this->assertSame(['allowed' => null, 'taken' => 1.0, 'balance' => null], $asha->types[$this->leave('LWP')]);
    }

    public function test_loan_is_paid_and_recovered_through_payroll(): void
    {
        $loan = $this->loan($this->asha, '5000', '2000');
        $voucher = $loan->voucher;
        $this->assertSame(Voucher::POSTED, $voucher->status);
        $this->assertSame(EmployeeLoan::class, $voucher->source_type);
        $this->assertSame(
            ['salary_advances' => 'Dr 5000.00', 'cash' => 'Cr 5000.00'],
            $voucher->entries()->with('account')->get()->mapWithKeys(fn ($e) => [$e->account->system_key => $e->side().' '.Money::fromPaise($e->amountPaise())])->all(),
        );

        $service = app(PayrollService::class);
        foreach (['2026-10' => '2000.00', '2026-11' => '2000.00', '2026-12' => '1000.00'] as $month => $expected) {
            $run = $service->prepare($month, $this->owner);
            $slip = $run->payslips->firstWhere('employee_id', $this->asha->id);
            $this->assertSame($expected, $this->amounts($slip)['loan_recovery'], $month);
            $this->assertSame(Money::fromPaise(1801100 - Money::toPaise($expected)), $slip->net);
            $run = $service->post($run, $this->owner);
            if ($month === '2026-10') {
                $credits = $run->voucher->entries()->with('account')->get()->filter(fn ($e) => $e->account->system_key === 'salary_advances');
                $this->assertSame(['Cr 2000.00'], $credits->map(fn ($e) => $e->side().' '.Money::fromPaise($e->amountPaise()))->values()->all());
            }
        }
        $this->assertSame(0, $loan->fresh()->outstandingPaise());
        $jan = $service->prepare('2027-01', $this->owner)->payslips->firstWhere('employee_id', $this->asha->id);
        $this->assertArrayNotHasKey('loan_recovery', $this->amounts($jan)); // repaid

        // Cancelling December's payroll puts its recovery back.
        $service->cancel(PayrollRun::where('month', '2026-12')->first(), $this->owner, 'Wrong');
        $this->assertSame(100000, $loan->fresh()->outstandingPaise());
    }

    public function test_recovery_is_limited_to_net_pay_and_rechecked_on_posting(): void
    {
        $this->loan($this->ravi, '100000', '50000');
        $service = app(PayrollService::class);
        $october = $service->prepare('2026-10', $this->owner);
        $ravi = $october->payslips->firstWhere('employee_id', $this->ravi->id);
        $this->assertSame(['21306.45', '0.00'], [$this->amounts($ravi)['loan_recovery'], $ravi->net]);

        // Two drafts both take Asha's last instalment; the second cannot be posted.
        $loan = $this->loan($this->asha, '3000', '2000');
        $october = $service->recalculate($october, []);
        $november = $service->prepare('2026-11', $this->owner);
        $this->assertSame('2000.00', $this->amounts($november->payslips->firstWhere('employee_id', $this->asha->id))['loan_recovery']);
        $service->post($october, $this->owner);
        $this->assertSame(100000, $loan->fresh()->outstandingPaise());
        try {
            $service->post($november->fresh(), $this->owner);
            $this->fail('Posting should be refused');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('Recalculate', $e->errors()['run'][0]);
        }
        $november = $service->recalculate($november->fresh(), []);
        $this->assertSame('1000.00', $this->amounts($november->payslips->firstWhere('employee_id', $this->asha->id))['loan_recovery']);
        $this->assertSame('posted', $service->post($november, $this->owner)->status);
    }

    public function test_loan_validation_and_cancel(): void
    {
        $service = app(LoanService::class);
        try {
            $service->create(['employee_id' => $this->asha->id, 'kind' => 'loan', 'date' => '2026-10-05', 'amount' => '1000', 'installment' => '2000',
                'recover_from' => '2026-09', 'paid_account_id' => Account::idFor('salaries')], $this->owner);
            $this->fail('Expected validation errors');
        } catch (ValidationException $e) {
            $this->assertEqualsCanonicalizing(['installment', 'recover_from', 'paid_account_id'], array_keys($e->errors()));
        }

        $loan = $this->loan($this->asha, '5000', '1000');
        $run = app(PayrollService::class)->prepare('2026-10', $this->owner);
        try {
            $service->cancel($loan, $this->owner, 'Mistake');
            $this->fail('Cancel should be refused while payroll recovers it');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('loan', $e->errors());
        }
        app(PayrollService::class)->delete($run);
        $service->cancel($loan, $this->owner, 'Mistake');
        $this->assertSame('cancelled', $loan->fresh()->status);
        $this->assertTrue($loan->voucher->fresh()->isCancelled());
        $this->assertArrayNotHasKey('loan_recovery', $this->amounts(app(PayrollService::class)->prepare('2026-10', $this->owner)->payslips->firstWhere('employee_id', $this->asha->id)));
    }

    public function test_screens_and_permissions(): void
    {
        $this->actingAs($this->owner);
        $this->get(route('attendance.index', ['month' => '2026-10']))->assertOk()->assertSee('Asha Patil')->assertSee('Casual Leave');
        $this->put(route('attendance.update'), [
            'month' => '2026-10',
            'days' => [$this->asha->id => json_encode(['6' => 'absent', '13' => 'leave:'.$this->leave('CL'), '7' => ''])],
            'overtime' => [$this->asha->id => '4', $this->ravi->id => ''],
        ])->assertRedirect(route('attendance.index', ['month' => '2026-10']));
        $this->assertSame(2, AttendanceDay::count());
        $this->put(route('attendance.update'), ['month' => '2026-10', 'overtime' => [$this->asha->id => 'abc']])->assertSessionHasErrors('overtime.'.$this->asha->id);
        $this->get(route('attendance.index', ['month' => '2026-10']))->assertOk()->assertSee('30'); // paid days
        $this->get(route('attendance.index', ['month' => '2026-10', 'export' => 'csv']))->assertOk();
        $this->get(route('leave-register', ['date' => '2026-10-31']))->assertOk()->assertSee('11'); // CL balance
        $this->get(route('leave-register', ['export' => 'csv']))->assertOk();

        $this->get(route('leave-types.index'))->assertOk();
        $this->post(route('leave-types.store'), ['name' => 'Maternity Leave', 'code' => 'ml', 'annual_days' => '182', 'is_paid' => 1, 'is_active' => 1])->assertRedirect();
        $this->assertSame('ML', LeaveType::where('name', 'Maternity Leave')->value('code'));
        $this->post(route('leave-types.store'), ['name' => 'Other', 'code' => 'CL'])->assertSessionHasErrors('code');
        $cl = LeaveType::where('code', 'CL')->first();
        $this->put(route('leave-types.update', $cl), ['name' => 'Casual Leave', 'code' => 'CL', 'is_paid' => 0, 'is_active' => 1])->assertSessionHasErrors('is_paid');
        $this->delete(route('leave-types.destroy', $cl))->assertSessionHas('error');

        $this->get(route('employee-loans.create'))->assertOk();
        $this->post(route('employee-loans.store'), [
            'employee_id' => $this->asha->id, 'kind' => 'loan', 'date' => '2026-10-05', 'amount' => '6000', 'installment' => '1500',
            'recover_from' => '2026-11', 'paid_account_id' => Account::idFor('cash'), 'description' => 'Medical',
        ])->assertRedirect();
        $loan = EmployeeLoan::firstOrFail();
        $this->get(route('employee-loans.index'))->assertOk()->assertSee('6,000.00');
        $this->get(route('employee-loans.index', ['export' => 'csv']))->assertOk();
        $this->get(route('employee-loans.show', $loan))->assertOk()->assertSee('Medical');
        $this->get(route('vouchers.show', $loan->voucher_id))->assertOk()->assertSee('loan to Asha Patil');
        $this->get(route('employees.show', $this->asha))->assertOk()->assertSee('6,000.00 due');
        $this->get(route('employees.edit', $this->asha))->assertOk()->assertDontSee('Loan / Advance Recovery');
        $this->delete(route('employees.destroy', $this->asha))->assertSessionHas('error');

        $this->post(route('payroll-runs.store'), ['month' => '2026-10'])->assertRedirect();
        $run = PayrollRun::firstOrFail();
        $this->post(route('payroll-runs.attendance', $run))->assertRedirect();
        $this->get(route('payroll-runs.show', $run))->assertOk()->assertSee('OT 4 h', false);

        $viewer = $this->addMember($this->company, 'viewer');
        $this->actingAs($viewer)->get(route('attendance.index'))->assertOk();
        $this->get(route('employee-loans.index'))->assertOk();
        $this->put(route('attendance.update'), ['month' => '2026-10'])->assertForbidden();
        $this->post(route('employee-loans.store'), [])->assertForbidden();
        $this->post(route('employee-loans.cancel', $loan), ['reason' => 'x'])->assertForbidden();
        $this->post(route('leave-types.store'), [])->assertForbidden();

        $sales = $this->addMember($this->company, 'sales');
        $this->actingAs($sales)->get(route('attendance.index'))->assertForbidden();
    }
}
