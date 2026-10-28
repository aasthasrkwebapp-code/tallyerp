<?php

namespace App\Http\Controllers\Payroll;

use App\Http\Controllers\Controller;
use App\Models\AttendanceDay;
use App\Models\LeaveType;
use App\Services\Payroll\AttendanceService;
use App\Support\CsvExport;
use App\Support\CurrentCompany;
use App\Support\Decimal;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** The monthly attendance register, the leave register and leave types. */
class AttendanceController extends Controller
{
    public function __construct(protected AttendanceService $attendance) {}

    public function index(Request $request)
    {
        $month = $request->input('month', now()->format('Y-m'));
        if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string) $month)) {
            $month = now()->format('Y-m');
        }
        [$start, $end] = $this->attendance->monthRange($month);
        $register = $this->attendance->register($month);
        $leaveTypes = LeaveType::query()->where('is_active', true)->orderBy('name')->get();

        if (CsvExport::requested($request)) {
            $codes = $this->codes($leaveTypes);
            $header = array_merge(['Code', 'Employee'], range(1, $end->day), ['Present', 'Absent', 'Paid leave', 'Leave without pay', 'Off / holiday', 'Overtime hours', 'Paid days']);
            $rows = $register->map(fn ($r) => array_merge(
                [$r->employee->code, $r->employee->name],
                array_map(fn ($d) => $d < $r->on_rolls[0] || $d > $r->on_rolls[1] ? '' : $this->short($r->days[$d] ?? ['present', null], $codes), range(1, $end->day)),
                [$r->summary['present'], $r->summary['absent'], $r->summary['leave'], $r->summary['unpaid_leave'], $r->summary['off'], $r->overtime, $r->paid_days],
            ));

            return CsvExport::download("attendance-{$month}.csv", $header, $rows->values());
        }

        return view('payroll.attendance.index', [
            'month' => $month,
            'start' => $start,
            'end' => $end,
            'register' => $register,
            'leaveTypes' => $leaveTypes,
        ]);
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'month' => ['required', 'string'],
            'cells' => ['nullable', 'array'],
            'cells.*' => ['nullable', 'array'],
            'days' => ['nullable', 'array'],
            'days.*' => ['nullable', 'string', 'max:2000'],
            'overtime' => ['nullable', 'array'],
        ]);
        // The register sends each employee's month as one JSON field (day => code); plain cells also work.
        $cells = $data['cells'] ?? [];
        foreach ($data['days'] ?? [] as $employeeId => $json) {
            $decoded = json_decode((string) $json, true);
            $cells[$employeeId] = is_array($decoded) ? $decoded : [];
        }
        $count = $this->attendance->save($data['month'], $cells, $data['overtime'] ?? []);

        return redirect()->route('attendance.index', ['month' => $data['month']])->with('success', "Attendance saved for {$count} ".str('employee')->plural($count).'.');
    }

    public function leaveRegister(Request $request)
    {
        $date = $request->input('date', now()->toDateString());
        $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $date) ? $date : now()->toDateString();
        $report = $this->attendance->leaveRegister($date);

        if (CsvExport::requested($request)) {
            $header = ['Code', 'Employee'];
            foreach ($report['types'] as $t) {
                array_push($header, "{$t->code} allowed", "{$t->code} taken", "{$t->code} balance");
            }
            $fmt = fn ($v) => $v === null ? '' : Decimal::trim(number_format($v, 1, '.', ''));
            $rows = $report['rows']->map(function ($r) use ($report, $fmt) {
                $row = [$r->employee->code, $r->employee->name];
                foreach ($report['types'] as $t) {
                    $c = $r->types[$t->id];
                    array_push($row, $fmt($c['allowed']), $fmt($c['taken']), $fmt($c['balance']));
                }

                return $row;
            });

            return CsvExport::download("leave-register-{$report['from']}.csv", $header, $rows->values());
        }

        return view('payroll.attendance.leave-register', $report + ['date' => $date]);
    }

    public function leaveTypes(Request $request)
    {
        $types = LeaveType::query()->orderBy('name')->get();

        return view('payroll.attendance.leave-types', [
            'types' => $types,
            'editing' => $request->filled('edit') ? $types->firstWhere('id', $request->integer('edit')) : null,
            'usage' => AttendanceDay::query()->whereNotNull('leave_type_id')->selectRaw('leave_type_id, count(*) as c')->groupBy('leave_type_id')->pluck('c', 'leave_type_id'),
        ]);
    }

    public function storeLeaveType(Request $request, CurrentCompany $current)
    {
        $type = LeaveType::create($this->validatedLeaveType($request, $current));

        return redirect()->route('leave-types.index')->with('success', "Leave type {$type->name} created.");
    }

    public function updateLeaveType(Request $request, CurrentCompany $current, LeaveType $leaveType)
    {
        $leaveType->update($this->validatedLeaveType($request, $current, $leaveType));

        return redirect()->route('leave-types.index')->with('success', "Leave type {$leaveType->name} updated.");
    }

    public function destroyLeaveType(LeaveType $leaveType)
    {
        if (AttendanceDay::query()->where('leave_type_id', $leaveType->id)->exists()) {
            return back()->with('error', 'This leave type is used in attendance and cannot be deleted. Mark it inactive instead.');
        }
        $leaveType->delete();

        return redirect()->route('leave-types.index')->with('success', "Leave type {$leaveType->name} deleted.");
    }

    protected function validatedLeaveType(Request $request, CurrentCompany $current, ?LeaveType $type = null): array
    {
        $request->merge(['code' => strtoupper(trim((string) $request->input('code')))]);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:50', Rule::unique('leave_types')->where('company_id', $current->id())->ignore($type?->id)],
            'code' => ['required', 'string', 'max:10', 'regex:/^[A-Z0-9]+$/', Rule::unique('leave_types')->where('company_id', $current->id())->ignore($type?->id)],
            'annual_days' => ['nullable', 'numeric', 'min:0', 'max:366', 'regex:/^\d{1,3}(\.[05])?$/'],
        ], ['code.regex' => 'Use letters and digits only, e.g. CL.', 'annual_days.regex' => 'Use whole or half days.']);
        $data['is_paid'] = $request->boolean('is_paid');
        $data['is_active'] = $request->boolean('is_active');
        if ($type && $type->is_paid !== $data['is_paid'] && AttendanceDay::query()->where('leave_type_id', $type->id)->exists()) {
            throw ValidationException::withMessages(['is_paid' => 'This leave type is already used in attendance, so paid / unpaid cannot change. Make a new leave type.']);
        }

        return $data;
    }

    /** "leave:<id>" => leave code */
    protected function codes($leaveTypes): array
    {
        return LeaveType::query()->pluck('code', 'id')->all();
    }

    protected function short(array $entry, array $codes): string
    {
        [$status, $leaveType] = $entry;
        if ($leaveType) {
            return ($status === 'half_leave' ? '½' : '').($codes[$leaveType] ?? 'L');
        }

        return AttendanceDay::STATUSES[$status][0];
    }
}
