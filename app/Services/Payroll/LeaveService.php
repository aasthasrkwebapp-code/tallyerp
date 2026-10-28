<?php

namespace App\Services\Payroll;

use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\EmployeePayHead;
use App\Models\LeaveEncashment;
use App\Models\LeaveOpening;
use App\Models\LeaveType;
use App\Models\User;
use App\Models\Voucher;
use App\Models\VoucherType;
use App\Services\Accounting\VoucherService;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Opening leave balances and leave encashment.
 *
 * Opening balances are each employee's unused leave when the books began; the leave register
 * carries them into the first year. Encashment pays unused leave of a type with a yearly
 * allowance by a payment voucher (Dr the salaries ledger, Cr cash or bank) and takes the days
 * off that year's balance, as leave taken would.
 */
class LeaveService
{
    public function __construct(protected AttendanceService $attendance, protected VoucherService $vouchers) {}

    /** Leave types that keep a balance: a yearly allowance and active. */
    public function balanceTypes()
    {
        return LeaveType::query()->whereNotNull('annual_days')->where('is_active', true)->orderBy('name')->get();
    }

    /**
     * Save opening balances.
     *
     * @param  array<int, array<int, ?string>>  $days  employee id => [leave type id => days]
     */
    public function saveOpenings(array $days): int
    {
        $types = $this->balanceTypes()->keyBy('id');
        $employees = Employee::query()->pluck('id')->flip();
        $errors = [];
        $clean = [];
        foreach ($days as $employeeId => $byType) {
            foreach ((array) $byType as $typeId => $value) {
                if (! isset($employees[(int) $employeeId], $types[(int) $typeId])) {
                    continue;
                }
                $value = trim((string) $value);
                if ($value !== '' && (! preg_match('/^\d{1,3}(\.[05]0?)?$/', $value) || (float) $value > 999)) {
                    $errors["days.$employeeId.$typeId"] = 'Enter days in whole or half days, such as 7 or 7.5.';

                    continue;
                }
                $clean[(int) $employeeId][(int) $typeId] = $value === '' ? null : $value;
            }
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        return DB::transaction(function () use ($clean) {
            $changed = 0;
            $existing = LeaveOpening::query()->get()->keyBy(fn ($o) => $o->employee_id.'|'.$o->leave_type_id);
            foreach ($clean as $employeeId => $byType) {
                foreach ($byType as $typeId => $value) {
                    $row = $existing->get("$employeeId|$typeId");
                    if ($value === null || (float) $value == 0) {
                        if ($row) {
                            $row->delete();
                            $changed++;
                        }
                    } elseif (! $row) {
                        LeaveOpening::create(['employee_id' => $employeeId, 'leave_type_id' => $typeId, 'days' => $value]);
                        $changed++;
                    } elseif ((float) $row->days != (float) $value) {
                        $row->update(['days' => $value]);
                        $changed++;
                    }
                }
            }

            return $changed;
        });
    }

    /** A suggested rate per day: the employee's monthly basic salary divided by 30. */
    public function suggestedRate(Employee $employee): int
    {
        $basic = EmployeePayHead::query()->where('employee_id', $employee->id)
            ->whereHas('payHead', fn ($q) => $q->where('is_basic', true))->get()->sum(fn ($h) => Money::toPaise($h->amount));

        return intdiv($basic, 30);
    }

    public function paidFromQuery()
    {
        return Account::query()->where('is_active', true)->whereIn('account_type', ['cash', 'bank']);
    }

    /**
     * @param  array{employee_id: int, leave_type_id: int, date: string, days: string, rate: string, paid_account_id: int, narration?: ?string}  $data
     */
    public function encash(array $data, User $user): LeaveEncashment
    {
        $errors = [];
        $employee = Employee::query()->where('is_active', true)->find($data['employee_id'] ?? 0);
        $type = $this->balanceTypes()->firstWhere('id', (int) ($data['leave_type_id'] ?? 0));
        $paid = $this->paidFromQuery()->find($data['paid_account_id'] ?? 0);
        $days = trim((string) ($data['days'] ?? ''));
        $rate = Money::toPaise(str_replace(',', '', (string) ($data['rate'] ?? '0')));
        if (! $employee) {
            $errors['employee_id'] = 'Choose an active employee.';
        }
        if (! $type) {
            $errors['leave_type_id'] = 'Choose a leave type with a yearly allowance.';
        } elseif (! $type->is_paid) {
            $errors['leave_type_id'] = 'Unpaid leave cannot be encashed.';
        }
        if (! preg_match('/^\d{1,3}(\.[05]0?)?$/', $days) || (float) $days <= 0) {
            $errors['days'] = 'Enter the days to encash, in whole or half days.';
        }
        if ($rate <= 0) {
            $errors['rate'] = 'Enter the amount paid per day.';
        }
        if (! $paid) {
            $errors['paid_account_id'] = 'Choose the cash or bank ledger it is paid from.';
        }
        if ($employee && ($data['date'] ?? '') < $employee->date_of_joining->toDateString()) {
            $errors['date'] = 'The date is before the employee joined.';
        }
        if (! $errors) {
            $balance = $this->attendance->leaveBalance($employee, $type, $data['date'])['balance'] ?? 0;
            if ((float) $days > $balance) {
                $errors['days'] = "{$employee->name} has ".rtrim(rtrim(number_format(max(0, $balance), 1, '.', ''), '0'), '.')." day(s) of {$type->name} left this year.";
            }
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
        $amount = intdiv((int) round((float) $days * 10) * $rate, 10);

        return DB::transaction(function () use ($data, $user, $employee, $type, $paid, $days, $rate, $amount) {
            $encashment = LeaveEncashment::create([
                'employee_id' => $employee->id, 'leave_type_id' => $type->id, 'date' => $data['date'], 'days' => $days,
                'rate' => Money::fromPaise($rate), 'amount' => Money::fromPaise($amount),
                'expense_account_id' => Account::idFor('salaries'), 'paid_account_id' => $paid->id, 'status' => 'active',
                'narration' => isset($data['narration']) ? mb_substr(trim((string) $data['narration']), 0, 255) ?: null : null,
                'created_by' => $user->id,
            ]);
            try {
                $voucher = $this->vouchers->create([
                    'voucher_type_id' => VoucherType::forBase('payment')?->id,
                    'date' => $data['date'],
                    'narration' => "Leave encashment: {$days} day(s) of {$type->name} to {$employee->name} ({$employee->code}) at ".Money::format($rate, paiseInput: true).' a day'
                        .($encashment->narration ? ' · '.$encashment->narration : ''),
                    'entries' => [
                        ['account_id' => $encashment->expense_account_id, 'side' => 'Dr', 'amount' => Money::fromPaise($amount)],
                        ['account_id' => $paid->id, 'side' => 'Cr', 'amount' => Money::fromPaise($amount)],
                    ],
                ], $user, Voucher::POSTED, $encashment);
            } catch (ValidationException $e) {
                throw ValidationException::withMessages(collect($e->errors())->mapWithKeys(fn ($m, $k) => [str_starts_with($k, 'date') ? 'date' : 'rate' => $m])->all());
            }
            $encashment->update(['voucher_id' => $voucher->id]);

            return $encashment->fresh('voucher');
        });
    }

    public function cancel(LeaveEncashment $encashment, User $user, string $reason): LeaveEncashment
    {
        if ($encashment->status !== 'active') {
            throw ValidationException::withMessages(['encashment' => 'This encashment is already cancelled.']);
        }

        return DB::transaction(function () use ($encashment, $user, $reason) {
            if ($encashment->voucher && ! $encashment->voucher->isCancelled()) {
                $this->vouchers->cancel($encashment->voucher, $user, $reason);
            }
            $encashment->update(['status' => 'cancelled', 'cancellation_reason' => mb_substr($reason, 0, 255)]);
            AuditLog::record('cancelled', $encashment, null, null, $encashment->auditLabel().' cancelled: '.$reason);

            return $encashment;
        });
    }
}
