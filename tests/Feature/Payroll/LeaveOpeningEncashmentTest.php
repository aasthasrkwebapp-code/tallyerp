<?php

namespace Tests\Feature\Payroll;

use App\Models\Account;
use App\Models\Employee;
use App\Models\EmployeePayHead;
use App\Models\LeaveEncashment;
use App\Models\LeaveOpening;
use App\Models\LeaveType;
use App\Models\PayHead;
use App\Services\Payroll\AttendanceService;
use App\Services\Payroll\LeaveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class LeaveOpeningEncashmentTest extends TestCase
{
    use RefreshDatabase;

    protected $owner;
    protected $company;
    protected Employee $asha;
    protected LeaveType $el;
    protected LeaveType $cl;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->owner, $this->company] = $this->createCompanyWithOwner();
        $this->company->update(['books_begin_date' => '2025-04-01']);
        \App\Models\FinancialYear::open($this->company, '2025-04-01', makeCurrent: false);
        $this->asha = Employee::create(['code' => 'EMP001', 'name' => 'Asha Patil', 'date_of_joining' => '2020-06-01']);
        EmployeePayHead::create(['employee_id' => $this->asha->id, 'pay_head_id' => PayHead::where('system_key', 'basic')->value('id'), 'amount' => '30000']);
        $this->el = LeaveType::where('code', 'EL')->firstOrFail();
        $this->el->update(['carry_forward' => true, 'max_carry_forward' => '30']);
        $this->cl = LeaveType::where('code', 'CL')->firstOrFail();
    }

    protected function balance(LeaveType $type, string $date): array
    {
        return app(AttendanceService::class)->leaveRegister($date)['rows']->firstWhere('employee.id', $this->asha->id)->types[$type->id];
    }

    protected function encash(array $data = []): LeaveEncashment
    {
        return app(LeaveService::class)->encash($data + ['employee_id' => $this->asha->id, 'leave_type_id' => $this->el->id, 'date' => '2026-10-10',
            'days' => '5', 'rate' => '1000', 'paid_account_id' => Account::idFor('cash')], $this->owner);
    }

    public function test_openings_carry_into_the_first_year(): void
    {
        $this->assertSame(2, app(LeaveService::class)->saveOpenings([$this->asha->id => [$this->el->id => '10', $this->cl->id => '2.5']]));
        // FY 2025-26: EL 15 + 10; CL 12 + 2.5 for this year only.
        $this->assertSame(['carried' => 10.0, 'allowed' => 25.0, 'taken' => 0.0, 'encashed' => 0.0, 'balance' => 25.0], $this->balance($this->el, '2025-12-31'));
        $this->assertSame(14.5, $this->balance($this->cl, '2025-12-31')['allowed']);
        // FY 2026-27: EL carries 25 (limit 30); CL starts afresh.
        $this->assertSame(['carried' => 25.0, 'allowed' => 40.0], array_slice($this->balance($this->el, '2026-10-31'), 0, 2));
        $this->assertSame(['carried' => 0.0, 'allowed' => 12.0], array_slice($this->balance($this->cl, '2026-10-31'), 0, 2));

        // Blank or 0 removes; bad values are refused.
        app(LeaveService::class)->saveOpenings([$this->asha->id => [$this->cl->id => '']]);
        $this->assertSame(1, LeaveOpening::count());
        try {
            app(LeaveService::class)->saveOpenings([$this->asha->id => [$this->el->id => '2.3']]);
            $this->fail('Accepted');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey("days.{$this->asha->id}.{$this->el->id}", $e->errors());
        }
    }

    public function test_encashment_pays_and_reduces_the_balance(): void
    {
        app(LeaveService::class)->saveOpenings([$this->asha->id => [$this->el->id => '10']]);
        $this->assertSame(100000, app(LeaveService::class)->suggestedRate($this->asha)); // 30,000 / 30

        // Encashed in FY 2025-26: carries less into the next year.
        $this->encash(['date' => '2026-02-01', 'days' => '3']);
        $this->assertSame(22.0, $this->balance($this->el, '2026-10-31')['carried']);

        $encashment = $this->encash(['days' => '5.5']);
        $this->assertSame('5500.00', $encashment->amount);
        $voucher = $encashment->voucher;
        $this->assertTrue($voucher->isPosted());
        $this->assertSame([Account::idFor('salaries'), 'Dr', '5500.00'], [$voucher->entries[0]->account_id, 'Dr', $voucher->entries[0]->debit]);
        $this->assertStringContainsString('Leave encashment: 5.5 day(s) of Earned Leave to Asha Patil', $voucher->narration);
        $this->assertSame(['encashed' => 5.5, 'balance' => 31.5], array_slice($this->balance($this->el, '2026-10-31'), 3, 2));

        // More than the balance, unpaid leave and a bad amount are refused.
        foreach ([[['days' => '32'], 'days'], [['leave_type_id' => LeaveType::where('code', 'LWP')->value('id')], 'leave_type_id'], [['rate' => '0'], 'rate']] as [$data, $key]) {
            try {
                $this->encash($data);
                $this->fail('Accepted');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey($key, $e->errors());
            }
        }

        // Cancelling restores the balance and cancels the voucher.
        app(LeaveService::class)->cancel($encashment, $this->owner, 'Wrong days');
        $this->assertTrue($voucher->fresh()->isCancelled());
        $this->assertSame(37.0, $this->balance($this->el, '2026-10-31')['balance']);
    }

    public function test_screens_and_permissions(): void
    {
        $this->actingAs($this->owner)->get(route('leave-openings'))->assertOk()->assertSee('Asha Patil')->assertSee('Earned Leave (EL)');
        $this->put(route('leave-openings.update'), ['days' => [$this->asha->id => [$this->el->id => '8']]])->assertRedirect()->assertSessionHas('success', '1 opening balance(s) saved.');
        $this->get(route('leave-register', ['date' => '2025-10-01']))->assertOk()->assertSee('Carried in')->assertSee('23');

        $this->get(route('leave-encashments.create', ['employee_id' => $this->asha->id, 'date' => '2026-10-10']))->assertOk()->assertSee('1000.00')->assertSee('38 days');
        $this->post(route('leave-encashments.store'), ['employee_id' => $this->asha->id, 'leave_type_id' => $this->el->id, 'date' => '2026-10-10', 'days' => '2',
            'rate' => '1000', 'paid_account_id' => Account::idFor('cash')])->assertRedirect(route('leave-encashments.index'))->assertSessionHas('success', fn ($m) => str_contains($m, '2,000.00'));
        $this->get(route('leave-encashments.index'))->assertOk()->assertSee('Asha Patil')->assertSee('2,000.00');
        $this->get(route('leave-register', ['date' => '2026-10-10']))->assertSee('Encashed');
        $this->assertStringContainsString('EL encashed', $this->get(route('leave-register', ['date' => '2026-10-10', 'export' => 'csv']))->streamedContent());

        $viewer = $this->addMember($this->company, 'viewer');
        $this->actingAs($viewer)->get(route('leave-openings'))->assertOk()->assertDontSee('Save opening balances');
        $this->actingAs($viewer)->put(route('leave-openings.update'), ['days' => []])->assertForbidden();
        $this->actingAs($viewer)->get(route('leave-encashments.create'))->assertForbidden();
        $this->actingAs($viewer)->post(route('leave-encashments.cancel', LeaveEncashment::first()), ['reason' => 'x'])->assertForbidden();

        // An employee with an encashment cannot be deleted.
        $this->actingAs($this->owner)->delete(route('employees.destroy', $this->asha))->assertSessionHas('error');
    }
}
