<?php

namespace App\Services\Payroll;

use App\Models\AttendanceDay;
use App\Models\Employee;
use App\Models\LeaveType;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use Throwable;

/**
 * Attendance from a CSV or Excel file, in one of two layouts:
 *
 *  - a list, one row per employee and day: Employee code, Date, Status and optionally
 *    Overtime hours (as a biometric or HR system exports it). Overtime of an employee's
 *    rows in a month is added up and replaces that month's overtime.
 *  - the register grid, as the attendance register exports it: Code, then a column per
 *    day (1 to 31) and optionally Overtime hours, for the month chosen on upload.
 *
 * Status is a code from the register (P, A, ½ or HD, W, H, a leave code such as CL,
 * or ½CL for half a day on leave) or a word (Present, Absent, Half day, Weekly off,
 * Holiday). Days in the file replace what the register has; other days are left alone.
 * A blank grid cell leaves the day unchanged.
 */
class AttendanceImport
{
    public const MAX_ROWS = 20000;

    public function __construct(protected AttendanceService $attendance) {}

    /**
     * @return array{layout: string, entries: array<string, array<int, array<int, string>>>, overtime: array<string, array<int, string>>,
     *               errors: list<array{row: int, message: string}>, rows: int, employees: int, days: int}
     */
    public function parse(string $path, string $extension, ?string $month = null): array
    {
        $raw = $this->raw($path, strtolower($extension));
        $header = array_map(fn ($h) => $this->normalise((string) $h), array_shift($raw) ?? []);
        if (isset($header[0])) {
            $header[0] = preg_replace('/^\x{FEFF}/u', '', $header[0]);
        }
        $raw = array_filter($raw, fn ($line) => implode('', array_map(fn ($v) => trim((string) $v), $line)) !== '');
        if (! $raw) {
            throw ValidationException::withMessages(['file' => 'The file has no data rows.']);
        }
        if (count($raw) > self::MAX_ROWS) {
            throw ValidationException::withMessages(['file' => 'A file can have at most '.self::MAX_ROWS.' rows. Split it into smaller files.']);
        }

        $codeCol = $this->column($header, ['employeecode', 'code', 'empcode', 'employeeid']);
        if ($codeCol === null) {
            throw ValidationException::withMessages(['file' => 'Missing column: Employee code.']);
        }
        $otCol = $this->column($header, ['overtimehours', 'overtime', 'othours', 'ot']);
        $employees = Employee::query()->get()->keyBy(fn ($e) => mb_strtoupper(trim($e->code)));
        $statuses = $this->statusCodes();

        $result = ['entries' => [], 'overtime' => [], 'errors' => []];
        $error = function (int $row, string $message) use (&$result) {
            $result['errors'][] = ['row' => $row, 'message' => $message];
        };
        $set = function (int $row, Employee $e, Carbon $date, string $code) use (&$result, $error) {
            if ($date->lt($e->date_of_joining) || ($e->date_of_leaving && $date->gt($e->date_of_leaving))) {
                $error($row, "{$e->code}: {$e->name} is not on the rolls on {$date->format('d M Y')}.");

                return;
            }
            $m = $date->format('Y-m');
            $existing = $result['entries'][$m][$e->id][$date->day] ?? null;
            if ($existing !== null && $existing !== $code) {
                $error($row, "{$e->code}: {$date->format('d M Y')} appears twice with different entries.");

                return;
            }
            $result['entries'][$m][$e->id][$date->day] = $code;
        };

        $dateCol = $this->column($header, ['date', 'attendancedate']);
        $statusCol = $this->column($header, ['status', 'attendance', 'entry', 'mark']);
        if ($dateCol !== null && $statusCol !== null) {
            $layout = 'list';
            foreach ($raw as $n => $line) {
                $row = $n + 2;
                $e = $this->employee($line[$codeCol] ?? '', $employees, $row, $error);
                $date = $this->date($line[$dateCol] ?? null);
                if (! $date) {
                    $error($row, 'Date "'.trim((string) ($line[$dateCol] ?? '')).'" is not a date (use YYYY-MM-DD or DD-MM-YYYY).');
                }
                $code = $this->status((string) ($line[$statusCol] ?? ''), $statuses);
                if ($code === null) {
                    $error($row, 'Status "'.trim((string) ($line[$statusCol] ?? '')).'" is not a register code or active leave code.');
                }
                if (! $e || ! $date || $code === null) {
                    continue;
                }
                $set($row, $e, $date, $code);
                if ($otCol !== null && trim((string) ($line[$otCol] ?? '')) !== '') {
                    $hours = $this->hours($line[$otCol], $row, $error);
                    if ($hours !== null) {
                        $m = $date->format('Y-m');
                        $result['overtime'][$m][$e->id] = (string) round((float) ($result['overtime'][$m][$e->id] ?? 0) + $hours, 2);
                    }
                }
            }
        } else {
            $dayCols = [];
            foreach ($header as $i => $h) {
                if (preg_match('/^([1-9]|[12]\d|3[01])$/', $h)) {
                    $dayCols[$i] = (int) $h;
                }
            }
            if (! $dayCols) {
                throw ValidationException::withMessages(['file' => 'Use either Employee code, Date and Status columns, or the register layout with Code and a column for each day (1 to 31).']);
            }
            $layout = 'grid';
            [$start, $end] = $this->attendance->monthRange((string) $month);
            foreach ($raw as $n => $line) {
                $row = $n + 2;
                $e = $this->employee($line[$codeCol] ?? '', $employees, $row, $error);
                if (! $e) {
                    continue;
                }
                foreach ($dayCols as $i => $day) {
                    $value = trim((string) ($line[$i] ?? ''));
                    if ($value === '') {
                        continue;
                    }
                    if ($day > $end->day) {
                        $error($row, "{$e->code}: {$start->format('F Y')} has no day {$day}.");

                        continue;
                    }
                    $code = $this->status($value, $statuses);
                    if ($code === null) {
                        $error($row, "{$e->code}, day {$day}: \"{$value}\" is not a register code or active leave code.");

                        continue;
                    }
                    $set($row, $e, $start->copy()->day($day), $code);
                }
                if ($otCol !== null && trim((string) ($line[$otCol] ?? '')) !== '') {
                    $hours = $this->hours($line[$otCol], $row, $error);
                    if ($hours !== null) {
                        $result['overtime'][$start->format('Y-m')][$e->id] = (string) $hours;
                    }
                }
            }
        }
        foreach ($result['overtime'] as $m => $hours) {
            foreach ($hours as $id => $h) {
                if ((float) $h > 400) {
                    $error(0, $employees->firstWhere('id', $id)?->code.": overtime of {$h} hours in {$m} is more than 400.");
                }
            }
        }

        ksort($result['entries']);
        $people = [];
        $days = 0;
        foreach ($result['entries'] as $m => $byEmployee) {
            foreach ($byEmployee as $id => $d) {
                $people[$id] = true;
                $days += count($d);
            }
        }
        foreach ($result['overtime'] as $byEmployee) {
            foreach (array_keys($byEmployee) as $id) {
                $people[$id] = true;
            }
        }

        return $result + ['layout' => $layout, 'rows' => count($raw), 'employees' => count($people), 'days' => $days];
    }

    /**
     * Write parsed entries into the register: for each month, the employee's other days are kept.
     *
     * @return int employee-months saved
     */
    public function apply(array $entries, array $overtime): int
    {
        $months = array_unique(array_merge(array_keys($entries), array_keys($overtime)));
        sort($months);
        $count = 0;
        DB::transaction(function () use ($months, $entries, $overtime, &$count) {
            foreach ($months as $month) {
                $ids = array_unique(array_merge(array_keys($entries[$month] ?? []), array_keys($overtime[$month] ?? [])));
                $employees = $this->attendance->employees($month)->whereIn('id', $ids);
                $cells = [];
                foreach ($this->attendance->register($month, $employees) as $r) {
                    $id = $r->employee->id;
                    foreach ($r->days as $day => [$status, $leaveType]) {
                        $cells[$id][$day] = $leaveType ? "{$status}:{$leaveType}" : $status;
                    }
                    foreach ($entries[$month][$id] ?? [] as $day => $code) {
                        $cells[$id][$day] = $code;
                    }
                    $cells[$id] ??= [];
                }
                $hours = array_intersect_key($overtime[$month] ?? [], $cells);
                $count += $this->attendance->save($month, $cells, $hours);
            }
        });

        return $count;
    }

    /** The template for the list layout. */
    public static function template(): array
    {
        return [['Employee code', 'Date', 'Status', 'Overtime hours'], [
            ['E001', now()->startOfMonth()->toDateString(), 'P', '2'],
            ['E001', now()->startOfMonth()->addDay()->toDateString(), 'CL', ''],
            ['E002', now()->startOfMonth()->toDateString(), 'A', ''],
        ]];
    }

    /** Upper-case code or word => register entry ("absent", "leave:3", ...). */
    protected function statusCodes(): array
    {
        $codes = [
            'P' => 'present', 'PRESENT' => 'present', 'A' => 'absent', 'ABSENT' => 'absent', 'AB' => 'absent',
            '½' => 'half_day', 'HD' => 'half_day', '1/2' => 'half_day', 'HALFDAY' => 'half_day',
            'W' => 'week_off', 'WO' => 'week_off', 'WEEKLYOFF' => 'week_off', 'WEEKOFF' => 'week_off', 'OFF' => 'week_off',
            'H' => 'holiday', 'HOL' => 'holiday', 'HOLIDAY' => 'holiday', 'PH' => 'holiday',
        ];
        foreach (LeaveType::query()->where('is_active', true)->get() as $t) {
            $code = mb_strtoupper($t->code);
            $codes[$code] ??= "leave:{$t->id}";
            foreach (["½{$code}", "1/2{$code}", "HALF{$code}", "{$code}/2", "{$code}½"] as $half) {
                $codes[$half] ??= "half_leave:{$t->id}";
            }
        }

        return $codes;
    }

    protected function status(string $value, array $codes): ?string
    {
        $key = mb_strtoupper(preg_replace('/[\s_\-]+/u', '', trim($value)));

        return $codes[$key] ?? null;
    }

    protected function employee($code, $employees, int $row, callable $error): ?Employee
    {
        $code = mb_strtoupper(trim((string) $code));
        if ($code === '') {
            $error($row, 'Employee code is empty.');

            return null;
        }
        $e = $employees->get($code);
        if (! $e) {
            $error($row, "No employee has the code {$code}.");
        } elseif (! $e->is_active) {
            $error($row, "{$code}: {$e->name} is inactive.");

            return null;
        }

        return $e;
    }

    protected function hours($value, int $row, callable $error): ?float
    {
        $value = trim((string) $value);
        if (! preg_match('/^\d{1,3}(\.\d{1,2})?$/', $value)) {
            $error($row, "Overtime \"{$value}\" must be hours, up to 2 decimals.");

            return null;
        }

        return (float) $value;
    }

    protected function date($value): ?Carbon
    {
        if (is_numeric($value) && (float) $value > 20000) {
            return Carbon::instance(ExcelDate::excelToDateTimeObject((float) $value))->startOfDay();
        }
        $value = trim((string) $value);
        foreach (['Y-m-d', 'd-m-Y', 'd/m/Y', 'd.m.Y', 'Y/m/d', 'd-M-Y', 'd M Y'] as $format) {
            $d = \DateTime::createFromFormat('!'.$format, $value);
            if ($d && ! \DateTime::getLastErrors()) {
                return Carbon::instance($d)->startOfDay();
            }
        }

        return null;
    }

    protected function column(array $header, array $names): ?int
    {
        foreach ($header as $i => $h) {
            if (in_array($h, $names, true)) {
                return $i;
            }
        }

        return null;
    }

    protected function normalise(string $header): string
    {
        return preg_replace('/[^a-z0-9]/', '', strtolower(trim($header)));
    }

    protected function raw(string $path, string $extension): array
    {
        if (in_array($extension, ['xlsx', 'xls', 'ods'], true)) {
            try {
                return IOFactory::load($path)->getActiveSheet()->toArray(null, true, false, false);
            } catch (Throwable) {
                throw ValidationException::withMessages(['file' => 'The spreadsheet could not be read. Save it as .xlsx or .csv and try again.']);
            }
        }
        $raw = [];
        $handle = fopen($path, 'r');
        $first = (string) fgets($handle);
        $delimiter = substr_count($first, ';') > substr_count($first, ',') ? ';' : (substr_count($first, "\t") > substr_count($first, ',') ? "\t" : ',');
        rewind($handle);
        while (($line = fgetcsv($handle, null, $delimiter)) !== false) {
            $raw[] = $line;
        }
        fclose($handle);

        return $raw;
    }
}
