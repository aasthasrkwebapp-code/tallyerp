<?php

namespace Tests\Feature\Payroll;

use App\Models\AttendanceDay;
use App\Models\AttendanceMonth;
use App\Models\Employee;
use App\Models\LeaveType;
use App\Services\Payroll\AttendanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class AttendanceImportTest extends TestCase
{
    use RefreshDatabase;

    protected $owner;
    protected $company;
    protected Employee $asha;
    protected Employee $ravi;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->owner, $this->company] = $this->createCompanyWithOwner();
        $this->asha = Employee::create(['code' => 'EMP001', 'name' => 'Asha Patil', 'date_of_joining' => '2025-01-01']);
        $this->ravi = Employee::create(['code' => 'EMP002', 'name' => 'Ravi Kumar', 'date_of_joining' => '2026-10-16']);
    }

    protected function leave(string $code): int
    {
        return LeaveType::where('code', $code)->value('id');
    }

    protected function csv(string $content, string $name = 'attendance.csv'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $content);
    }

    /** The payload the preview's import button sends. */
    protected function payload($response): string
    {
        $this->assertSame(1, preg_match('/name="payload" value="([^"]*)"/', $response->getContent(), $m));

        return html_entity_decode($m[1], ENT_QUOTES);
    }

    /** @return array<int, string> day => "status" or "status:leave" */
    protected function days(Employee $e, string $month): array
    {
        return AttendanceDay::where('employee_id', $e->id)->where('date', 'like', $month.'%')->orderBy('date')->get()
            ->mapWithKeys(fn ($d) => [$d->date->day => $d->status.($d->leave_type_id ? ':'.LeaveType::find($d->leave_type_id)->code : '')])->all();
    }

    public function test_unused_leave_is_carried_forward_up_to_the_limit(): void
    {
        $this->company->update(['books_begin_date' => '2024-04-01']);
        $el = LeaveType::where('code', 'EL')->firstOrFail();
        $el->update(['carry_forward' => true, 'max_carry_forward' => '30']);
        $service = app(AttendanceService::class);
        // FY 2024-25: 3 days taken of 15, so 12 carried. FY 2025-26: 15 + 12, 2.5 taken, 24.5 carried.
        $service->save('2025-02', [$this->asha->id => ['3' => 'leave:'.$el->id, '4' => 'leave:'.$el->id, '5' => 'leave:'.$el->id]], []);
        $service->save('2025-06', [$this->asha->id => ['9' => 'leave:'.$el->id, '10' => 'leave:'.$el->id, '11' => 'half_leave:'.$el->id]], []);
        $service->save('2026-11', [$this->asha->id => ['2' => 'leave:'.$el->id]], []);

        $rows = $service->leaveRegister('2026-11-30')['rows'];
        $this->assertSame(['carried' => 24.5, 'allowed' => 39.5, 'taken' => 1.0, 'balance' => 38.5], $rows->firstWhere('employee.id', $this->asha->id)->types[$el->id]);
        $this->assertSame(['carried' => 0.0, 'allowed' => 15.0, 'taken' => 0.0, 'balance' => 15.0], $rows->firstWhere('employee.id', $this->ravi->id)->types[$el->id]);
        $this->assertSame(['carried' => null, 'allowed' => 12.0, 'taken' => 0.0, 'balance' => 12.0], $rows->firstWhere('employee.id', $this->asha->id)->types[$this->leave('CL')]);
        // The year before shows its own carry-in.
        $this->assertSame(12.0, $service->leaveRegister('2025-12-31')['rows']->firstWhere('employee.id', $this->asha->id)->types[$el->id]['carried']);

        $el->update(['max_carry_forward' => '20']);
        $this->assertSame(20.0, $service->leaveRegister('2026-11-30')['rows']->firstWhere('employee.id', $this->asha->id)->types[$el->id]['carried']);

        // An overdrawn year carries nothing.
        $el->update(['max_carry_forward' => null]);
        $service->save('2025-08', [$this->asha->id => array_fill_keys(range(1, 31), 'leave:'.$el->id)], []);
        $this->assertSame(0.0, $service->leaveRegister('2026-11-30')['rows']->firstWhere('employee.id', $this->asha->id)->types[$el->id]['carried']);

        $this->actingAs($this->owner)->get(route('leave-register', ['date' => '2026-11-30']))->assertOk()->assertSee('Carried in');
        $this->assertStringContainsString('EL carried in', $this->get(route('leave-register', ['date' => '2026-11-30', 'export' => 'csv']))->streamedContent());
    }

    public function test_leave_type_carry_forward_settings(): void
    {
        $this->actingAs($this->owner);
        $cl = LeaveType::where('code', 'CL')->firstOrFail();
        $this->put(route('leave-types.update', $cl), ['name' => 'Casual Leave', 'code' => 'CL', 'annual_days' => '12', 'carry_forward' => '1', 'max_carry_forward' => '6', 'is_paid' => '1', 'is_active' => '1'])
            ->assertRedirect(route('leave-types.index'));
        $this->assertTrue($cl->fresh()->carry_forward);
        $this->assertSame('6.0', $cl->fresh()->max_carry_forward);
        $this->get(route('leave-types.index'))->assertSee('Yes, up to 6 days');

        $lwp = LeaveType::where('code', 'LWP')->firstOrFail();
        $this->put(route('leave-types.update', $lwp), ['name' => 'Leave Without Pay', 'code' => 'LWP', 'annual_days' => '', 'carry_forward' => '1', 'is_paid' => '0', 'is_active' => '1'])
            ->assertSessionHasErrors('carry_forward');

        // Unticking clears the limit.
        $this->put(route('leave-types.update', $cl), ['name' => 'Casual Leave', 'code' => 'CL', 'annual_days' => '12', 'carry_forward' => '0', 'max_carry_forward' => '6', 'is_paid' => '1', 'is_active' => '1']);
        $this->assertNull($cl->fresh()->max_carry_forward);
    }

    public function test_list_import_previews_then_merges_into_the_register(): void
    {
        $cl = $this->leave('CL');
        app(AttendanceService::class)->save('2026-10', [$this->asha->id => ['2' => 'holiday', '5' => 'absent']], [$this->asha->id => '7']);

        $file = "Employee code,Date,Status,Overtime hours\n"
            ."emp001,2026-10-05,P,2\n"
            ."EMP001,06-10-2026,cl,1.5\n"
            ."EMP001,2026-10-07,½CL,\n"
            ."EMP002,2026-10-20,Absent,\n"
            ."EMP002,2026-11-02,W,4\n";
        $preview = $this->actingAs($this->owner)->post(route('attendance.import.preview'), ['file' => $this->csv($file)])
            ->assertOk()->assertSee('list layout')->assertSee('Import 5 days')->assertSee('½CL × 1')->assertSee('3.5');
        $this->assertSame([2 => 'holiday', 5 => 'absent'], $this->days($this->asha, '2026-10'));

        $payload = $this->payload($preview);
        $this->assertSame([
            '2026-10' => [$this->asha->id => [5 => 'present', 6 => 'leave:'.$cl, 7 => 'half_leave:'.$cl], $this->ravi->id => [20 => 'absent']],
            '2026-11' => [$this->ravi->id => [2 => 'week_off']],
        ], json_decode($payload, true)['entries']);
        $this->post(route('attendance.import.confirm'), ['payload' => $payload])->assertRedirect(route('attendance.index', ['month' => '2026-11']));

        // Day 2 is kept, day 5 becomes present, 6 and 7 are leave.
        $this->assertSame([2 => 'holiday', 6 => 'leave:CL', 7 => 'half_leave:CL'], $this->days($this->asha, '2026-10'));
        $this->assertSame([20 => 'absent'], $this->days($this->ravi, '2026-10'));
        $this->assertSame([2 => 'week_off'], $this->days($this->ravi, '2026-11'));
        $this->assertSame('3.50', AttendanceMonth::where('employee_id', $this->asha->id)->where('month', '2026-10')->value('overtime_hours'));
        $this->assertSame('4.00', AttendanceMonth::where('employee_id', $this->ravi->id)->where('month', '2026-11')->value('overtime_hours'));
    }

    public function test_problems_block_the_import(): void
    {
        $file = "Employee code,Date,Status\n"
            ."EMP009,2026-10-05,P\n"         // no such employee
            ."EMP001,2026-13-05,P\n"         // bad date
            ."EMP001,2026-10-05,XX\n"        // bad status
            ."EMP002,2026-10-10,A\n"         // before joining
            ."EMP001,2026-10-08,A\n"
            ."EMP001,08/10/2026,W\n";        // same day twice
        $this->actingAs($this->owner)->post(route('attendance.import.preview'), ['file' => $this->csv($file)])
            ->assertOk()->assertSee('5 problems')->assertSee('No employee has the code EMP009')->assertSee('Row 3: Date')
            ->assertSee('Status &quot;XX&quot;', false)->assertSee('not on the rolls on 10 Oct 2026')->assertSee('appears twice')
            ->assertDontSee('attendance.import.confirm')->assertDontSee('name="payload"', false);

        $this->post(route('attendance.import.preview'), ['file' => $this->csv("Name,Value\nx,y\n")])->assertSessionHasErrors('file');
    }

    public function test_register_layout_round_trip_and_permissions(): void
    {
        // As the register's CSV export lays it out, with summary columns that are ignored.
        $file = "Code,Employee,1,2,3,4,Present,Overtime hours,Paid days\n"
            ."EMP001,Asha Patil,P,A,EL,½EL,28,6,30\n"
            ."EMP002,Ravi Kumar,,,,,0,,0\n";
        $payload = $this->payload($this->actingAs($this->owner)->post(route('attendance.import.preview'), ['file' => $this->csv($file), 'month' => '2026-10'])
            ->assertOk()->assertSee('register layout')->assertSee('Import 4 days'));
        $this->post(route('attendance.import.confirm'), ['payload' => $payload])->assertRedirect();
        $this->assertSame([2 => 'absent', 3 => 'leave:EL', 4 => 'half_leave:EL'], $this->days($this->asha, '2026-10'));
        $this->assertSame('6.00', AttendanceMonth::where('employee_id', $this->asha->id)->where('month', '2026-10')->value('overtime_hours'));
        // Paid leave is paid, so only the absent day is lost.
        $this->assertSame('30', app(AttendanceService::class)->register('2026-10')->firstWhere('employee.id', $this->asha->id)->paid_days);

        // A grid needs a month; day 31 of a 30-day month is refused.
        $this->post(route('attendance.import.preview'), ['file' => $this->csv("Code,31\nEMP001,A\n"), 'month' => '2026-11'])->assertSee('has no day 31');
        $this->post(route('attendance.import.preview'), ['file' => $this->csv("Code,1\nEMP001,A\n")])->assertSessionHasErrors('month');

        $this->get(route('attendance.import'))->assertOk()->assertSee('Import attendance');
        $this->assertStringContainsString('"Employee code",Date,Status', $this->get(route('attendance.import-template'))->streamedContent());
        $this->get(route('attendance.index', ['month' => '2026-10']))->assertSee(route('attendance.import', ['month' => '2026-10']), false);

        $viewer = $this->addMember($this->company, 'viewer');
        $this->actingAs($viewer)->get(route('attendance.import'))->assertForbidden();
        $this->actingAs($viewer)->post(route('attendance.import.confirm'), ['payload' => $payload])->assertForbidden();
    }
}
