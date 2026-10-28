<?php

namespace App\Services\Payroll;

use App\Models\AttendanceDay;
use App\Models\AttendanceMonth;
use App\Models\Employee;
use App\Models\LeaveType;
use App\Services\Reports\AccountBalances;
use App\Support\Decimal;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The monthly attendance register, paid days worked out from it, and the leave register.
 *
 * A day that is not marked counts as present. Paid days are the days on the
 * rolls less absent days, half of each half day, and leave without pay.
 * Paid leave, weekly offs and holidays are paid.
 */
class AttendanceService
{
    public function __construct(protected AccountBalances $balances) {}

    /** First and last day of a "YYYY-MM" month, or a validation error. */
    public function monthRange(string $month): array
    {
        if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
            throw ValidationException::withMessages(['month' => 'Choose a month.']);
        }
        $start = Carbon::createFromFormat('Y-m-d', $month.'-01')->startOfDay();

        return [$start, $start->copy()->endOfMonth()->startOfDay()];
    }

    /** Employees on the rolls for at least one day of the month. */
    public function employees(string $month): Collection
    {
        [$start, $end] = $this->monthRange($month);

        return Employee::query()->where('is_active', true)
            ->where('date_of_joining', '<=', $end->toDateString())
            ->where(fn ($q) => $q->whereNull('date_of_leaving')->orWhere('date_of_leaving', '>=', $start->toDateString()))
            ->orderBy('code')->get();
    }

    /**
     * The register for a month: for each employee, the marked days (day number => [status, leave type id]),
     * overtime hours and the paid days that result.
     *
     * @return Collection<int, object{employee: Employee, days: array, overtime: string, paid_days: string, on_rolls: array, marked: bool, summary: array}>
     */
    public function register(string $month, ?Collection $employees = null): Collection
    {
        [$start, $end] = $this->monthRange($month);
        $employees ??= $this->employees($month);
        $ids = $employees->pluck('id');
        $days = AttendanceDay::query()->whereIn('employee_id', $ids)->whereBetween('date', [$start->toDateString(), $end->toDateString()])->get()->groupBy('employee_id');
        $overtime = AttendanceMonth::query()->whereIn('employee_id', $ids)->where('month', $month)->pluck('overtime_hours', 'employee_id');
        $paidLeave = LeaveType::query()->pluck('is_paid', 'id');

        return $employees->map(function (Employee $e) use ($start, $end, $days, $overtime, $paidLeave) {
            $from = $e->date_of_joining->gt($start) ? $e->date_of_joining->day : 1;
            $to = $e->date_of_leaving && $e->date_of_leaving->lt($end) ? $e->date_of_leaving->day : $end->day;
            $marked = [];
            foreach ($days->get($e->id, collect()) as $d) {
                $marked[$d->date->day] = [$d->status, $d->leave_type_id];
            }

            // Half units, so 0.5 days stay exact.
            $paid = 0;
            $summary = ['present' => 0, 'absent' => 0, 'leave' => 0, 'unpaid_leave' => 0, 'off' => 0];
            for ($day = $from; $day <= $to; $day++) {
                [$status, $leaveType] = $marked[$day] ?? ['present', null];
                $leavePaid = $leaveType ? (bool) ($paidLeave[$leaveType] ?? true) : true;
                [$p, $s] = match ($status) {
                    'absent' => [0, ['absent' => 2]],
                    'half_day' => [1, ['present' => 1, 'absent' => 1]],
                    'leave' => [$leavePaid ? 2 : 0, [$leavePaid ? 'leave' : 'unpaid_leave' => 2]],
                    'half_leave' => [$leavePaid ? 2 : 1, ['present' => 1, $leavePaid ? 'leave' : 'unpaid_leave' => 1]],
                    'week_off', 'holiday' => [2, ['off' => 2]],
                    default => [2, ['present' => 2]],
                };
                $paid += $p;
                foreach ($s as $k => $v) {
                    $summary[$k] += $v;
                }
            }

            return (object) [
                'employee' => $e,
                'days' => $marked,
                'on_rolls' => [$from, $to],
                'marked' => $marked !== [],
                'overtime' => Decimal::trim((string) ($overtime[$e->id] ?? '0')),
                'paid_days' => Decimal::trim(number_format($paid / 2, 1, '.', '')),
                'summary' => array_map(fn ($v) => Decimal::trim(number_format($v / 2, 1, '.', '')), $summary),
            ];
        })->values();
    }

    /**
     * Save the register for a month. $cells: employee id => day => code, where code is
     * "" (present, not marked), a status key, or "leave:<id>" / "half_leave:<id>".
     * $overtime: employee id => hours.
     */
    public function save(string $month, array $cells, array $overtime): int
    {
        [$start, $end] = $this->monthRange($month);
        $employees = $this->employees($month)->keyBy('id');
        $leaveTypes = LeaveType::query()->where('is_active', true)->pluck('id')->all();
        $rows = [];
        $hours = [];
        $errors = [];

        foreach ($cells as $employeeId => $days) {
            $employee = $employees->get((int) $employeeId);
            if (! $employee || ! is_array($days)) {
                continue;
            }
            foreach ($days as $day => $code) {
                $day = (int) $day;
                $code = trim((string) $code);
                if ($day < 1 || $day > $end->day || $code === '') {
                    continue;
                }
                [$status, $leaveType] = array_pad(explode(':', $code, 2), 2, null);
                $date = $start->copy()->day($day);
                if (! isset(AttendanceDay::STATUSES[$status]) || (in_array($status, ['leave', 'half_leave'], true) !== ($leaveType !== null))
                    || ($leaveType !== null && ! in_array((int) $leaveType, $leaveTypes, true))) {
                    $errors["cells.$employeeId.$day"] = "{$employee->name}, {$date->format('d M')}: choose a valid entry.";

                    continue;
                }
                if ($date->lt($employee->date_of_joining) || ($employee->date_of_leaving && $date->gt($employee->date_of_leaving))) {
                    continue; // not on the rolls that day
                }
                if ($status === 'present') {
                    continue; // the default
                }
                $rows[$employee->id][] = ['date' => $date->toDateString(), 'status' => $status, 'leave_type_id' => $leaveType ? (int) $leaveType : null];
            }
        }
        foreach ($overtime as $employeeId => $value) {
            $employee = $employees->get((int) $employeeId);
            $value = trim((string) $value);
            if (! $employee) {
                continue;
            }
            if ($value !== '' && (! preg_match('/^\d{1,3}(\.\d{1,2})?$/', $value) || (float) $value > 400)) {
                $errors["overtime.$employeeId"] = "{$employee->name}: overtime must be hours between 0 and 400, up to 2 decimals.";

                continue;
            }
            $hours[$employee->id] = $value === '' ? '0' : $value;
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        DB::transaction(function () use ($start, $end, $month, $cells, $rows, $hours, $employees) {
            foreach (array_keys($cells) as $employeeId) {
                if (! $employees->has((int) $employeeId)) {
                    continue;
                }
                AttendanceDay::query()->where('employee_id', $employeeId)->whereBetween('date', [$start->toDateString(), $end->toDateString()])->delete();
                foreach ($rows[$employeeId] ?? [] as $row) {
                    AttendanceDay::create(['employee_id' => (int) $employeeId] + $row);
                }
            }
            foreach ($hours as $employeeId => $value) {
                AttendanceMonth::query()->updateOrCreate(['employee_id' => $employeeId, 'month' => $month], ['overtime_hours' => $value]);
            }
        });

        return count($cells);
    }

    /**
     * Leave register for the financial year that contains $date: for each employee and leave type,
     * the allowance, leave taken in the year and the balance.
     *
     * @return array{from: string, to: string, types: Collection, rows: Collection}
     */
    public function leaveRegister(string $date): array
    {
        $from = $this->balances->yearStart($date);
        $to = Carbon::parse($from)->addYear()->subDay()->toDateString();
        $types = LeaveType::query()->orderBy('is_paid', 'desc')->orderBy('name')->get();
        $taken = AttendanceDay::query()->whereBetween('date', [$from, $to])->whereNotNull('leave_type_id')
            ->selectRaw("employee_id, leave_type_id, SUM(CASE WHEN status = 'half_leave' THEN 1 ELSE 2 END) as halves")
            ->groupBy('employee_id', 'leave_type_id')->get()
            ->groupBy('employee_id')->map(fn ($c) => $c->pluck('halves', 'leave_type_id'));

        $employees = Employee::query()->where('date_of_joining', '<=', $to)
            ->where(fn ($q) => $q->whereNull('date_of_leaving')->orWhere('date_of_leaving', '>=', $from))
            ->orderBy('code')->get();

        $rows = $employees->map(fn (Employee $e) => (object) [
            'employee' => $e,
            'types' => $types->mapWithKeys(function (LeaveType $t) use ($taken, $e) {
                $used = ((int) ($taken[$e->id][$t->id] ?? 0)) / 2.0;
                $allowed = $t->annual_days !== null ? (float) $t->annual_days : null;

                return [$t->id => ['allowed' => $allowed, 'taken' => $used, 'balance' => $allowed !== null ? $allowed - $used : null]];
            }),
        ]);

        return ['from' => $from, 'to' => $to, 'types' => $types, 'rows' => $rows];
    }
}
