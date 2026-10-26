<?php

namespace App\Services\Payroll;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\PayHead;
use App\Models\PayrollRun;
use App\Models\User;
use App\Models\Voucher;
use App\Models\VoucherType;
use App\Services\Accounting\VoucherService;
use App\Support\CurrentCompany;
use App\Support\Decimal;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Monthly payroll: prepare payslips (draft), adjust paid days, post one
 * journal voucher for the month, cancel it.
 *
 * Posting debits each earning's expense ledger and each employer
 * contribution's expense ledger, and credits each deduction's payable ledger,
 * each employer contribution's payable ledger and Salary Payable with the net
 * pay. Paying salaries is then an ordinary payment voucher from the bank.
 */
class PayrollService
{
    public function __construct(
        protected CurrentCompany $current,
        protected PayrollCalculator $calculator,
        protected VoucherService $vouchers,
    ) {}

    /** Create a draft run for a month ("YYYY-MM") with a payslip for each employee on the rolls. */
    public function prepare(string $month, User $user): PayrollRun
    {
        if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
            throw ValidationException::withMessages(['month' => 'Choose a month.']);
        }
        if (PayrollRun::query()->where('month', $month)->where('status', '!=', 'cancelled')->exists()) {
            throw ValidationException::withMessages(['month' => 'Payroll for this month already exists. Open it, or cancel it first.']);
        }

        $start = Carbon::createFromFormat('Y-m-d', $month.'-01')->startOfDay();
        $end = $start->copy()->endOfMonth()->startOfDay();
        $employees = $this->employeesFor($start, $end);
        if ($employees->isEmpty()) {
            throw ValidationException::withMessages(['month' => 'No active employees were on the rolls in this month.']);
        }

        return DB::transaction(function () use ($month, $user, $start, $end, $employees) {
            $run = PayrollRun::create(['month' => $month, 'date' => $end->toDateString(), 'status' => 'draft', 'created_by' => $user->id]);
            $days = $start->daysInMonth;

            foreach ($employees as $employee) {
                // Days on the rolls this month (joining or leaving mid-month).
                $from = $employee->date_of_joining->gt($start) ? $employee->date_of_joining : $start;
                $to = $employee->date_of_leaving && $employee->date_of_leaving->lt($end) ? $employee->date_of_leaving : $end;
                $this->writePayslip($run, $employee, $days, (string) ($from->diffInDays($to) + 1));
            }

            return $this->refreshTotals($run);
        });
    }

    /**
     * Set paid days (employee id => days) on a draft run and recalculate.
     * Also picks up salary structure changes made since the run was prepared.
     */
    public function recalculate(PayrollRun $run, array $paidDays): PayrollRun
    {
        $this->assertDraft($run);
        $run->load('payslips');
        $errors = [];
        foreach ($run->payslips as $slip) {
            $value = trim((string) ($paidDays[$slip->employee_id] ?? Decimal::trim($slip->paid_days)));
            if (! preg_match('/^\d{1,2}(\.[05])?$/', $value) || (float) $value > $slip->days_in_month) {
                $errors["paid_days.{$slip->employee_id}"] = "{$slip->employee_name}: paid days must be between 0 and {$slip->days_in_month} (halves allowed).";
            }
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        return DB::transaction(function () use ($run, $paidDays) {
            foreach ($run->payslips as $slip) {
                $employee = Employee::query()->with('payHeads')->findOrFail($slip->employee_id);
                $value = trim((string) ($paidDays[$slip->employee_id] ?? Decimal::trim($slip->paid_days)));
                $slip->delete();
                $this->writePayslip($run, $employee, $slip->days_in_month, $value);
            }

            return $this->refreshTotals($run);
        });
    }

    public function post(PayrollRun $run, User $user, ?string $date = null): PayrollRun
    {
        $this->assertDraft($run);
        $run->load('payslips.lines');
        if ((float) $run->gross_total <= 0) {
            throw ValidationException::withMessages(['run' => 'There is nothing to post: the gross pay is zero.']);
        }
        $type = VoucherType::forBase('journal');
        $salaryPayable = \App\Models\Account::idFor('salary_payable');
        if (! $type || ! $salaryPayable) {
            throw ValidationException::withMessages(['run' => 'The Journal voucher type and the Salary Payable ledger are needed to post payroll.']);
        }

        return DB::transaction(function () use ($run, $user, $date, $type, $salaryPayable) {
            $date ??= $run->date->toDateString();
            $heads = PayHead::query()->whereIn('id', $run->payslips->flatMap->lines->pluck('pay_head_id')->unique())->get()->keyBy('id');
            $debits = [];
            $credits = [];
            foreach ($run->payslips->flatMap->lines as $line) {
                $head = $heads[$line->pay_head_id];
                $amount = Money::toPaise($line->amount);
                if ($amount === 0) {
                    continue;
                }
                match ($head->type) {
                    'earning' => $debits[$head->account_id] = ($debits[$head->account_id] ?? 0) + $amount,
                    'deduction' => $credits[$head->account_id] = ($credits[$head->account_id] ?? 0) + $amount,
                    'employer' => [
                        $debits[$head->account_id] = ($debits[$head->account_id] ?? 0) + $amount,
                        $credits[$head->payable_account_id ?? $head->account_id] = ($credits[$head->payable_account_id ?? $head->account_id] ?? 0) + $amount,
                    ],
                };
            }
            $net = Money::toPaise($run->net_total);
            if ($net > 0) {
                $credits[$salaryPayable] = ($credits[$salaryPayable] ?? 0) + $net;
            }

            $entries = [];
            foreach ($debits as $account => $paise) {
                $entries[] = ['account_id' => $account, 'side' => 'Dr', 'amount' => Money::fromPaise($paise)];
            }
            foreach ($credits as $account => $paise) {
                $entries[] = ['account_id' => $account, 'side' => 'Cr', 'amount' => Money::fromPaise($paise)];
            }

            $voucher = $this->vouchers->create([
                'voucher_type_id' => $type->id,
                'date' => $date,
                'narration' => 'Salary for '.$run->label().' ('.$run->payslips->count().' employees)',
                'entries' => $entries,
            ], $user, Voucher::POSTED, $run);

            $run->update(['status' => 'posted', 'voucher_id' => $voucher->id, 'date' => $date]);
            AuditLog::record('posted', $run, null, null, "Payroll {$run->label()} posted as voucher {$voucher->voucher_number}");

            return $run->fresh('voucher');
        });
    }

    public function cancel(PayrollRun $run, User $user, string $reason): PayrollRun
    {
        if ($run->status !== 'posted') {
            throw ValidationException::withMessages(['run' => 'Only posted payroll can be cancelled.']);
        }

        return DB::transaction(function () use ($run, $user, $reason) {
            if ($run->voucher && ! $run->voucher->isCancelled()) {
                $this->vouchers->cancel($run->voucher, $user, $reason);
            }
            $run->update(['status' => 'cancelled', 'cancellation_reason' => mb_substr($reason, 0, 255)]);
            AuditLog::record('cancelled', $run, null, null, "Payroll {$run->label()} cancelled: {$reason}");

            return $run;
        });
    }

    public function delete(PayrollRun $run): void
    {
        $this->assertDraft($run);
        $run->delete();
    }

    /** Employees on the rolls for at least one day between the dates. */
    public function employeesFor(Carbon $start, Carbon $end): Collection
    {
        return Employee::query()->with('payHeads')
            ->where('is_active', true)
            ->where('date_of_joining', '<=', $end->toDateString())
            ->where(fn ($q) => $q->whereNull('date_of_leaving')->orWhere('date_of_leaving', '>=', $start->toDateString()))
            ->orderBy('code')->get();
    }

    // ------------------------------------------------------------------

    protected function writePayslip(PayrollRun $run, Employee $employee, int $days, string $paidDays): void
    {
        $heads = PayHead::query()->where('is_active', true)->orderBy('sort_order')->orderBy('id')->get();
        $result = $this->calculator->calculate($employee, $heads, $days, $paidDays, (int) substr($run->month, 5, 2), $this->current->get()->state);

        $slip = $run->payslips()->create([
            'company_id' => $run->company_id,
            'employee_id' => $employee->id,
            'employee_code' => $employee->code,
            'employee_name' => $employee->name,
            'designation' => $employee->designation,
            'days_in_month' => $days,
            'paid_days' => $paidDays,
            'gross' => Money::fromPaise($result['gross']),
            'deductions' => Money::fromPaise($result['deductions']),
            'employer_contributions' => Money::fromPaise($result['employer']),
            'net' => Money::fromPaise($result['net']),
        ]);
        foreach ($result['lines'] as $line) {
            $slip->lines()->create([
                'company_id' => $run->company_id,
                'pay_head_id' => $line['head']->id,
                'name' => $line['head']->name,
                'type' => $line['head']->type,
                'amount' => Money::fromPaise($line['amount']),
            ]);
        }
    }

    protected function refreshTotals(PayrollRun $run): PayrollRun
    {
        $slips = $run->payslips()->get();
        $sum = fn (string $field) => Money::fromPaise($slips->sum(fn ($s) => Money::toPaise($s->{$field})));
        $run->update([
            'gross_total' => $sum('gross'),
            'deduction_total' => $sum('deductions'),
            'employer_total' => $sum('employer_contributions'),
            'net_total' => $sum('net'),
        ]);

        return $run->fresh('payslips.lines');
    }

    protected function assertDraft(PayrollRun $run): void
    {
        if (! $run->isDraft()) {
            throw ValidationException::withMessages(['run' => 'Only draft payroll can be changed. Cancel the posted payroll and prepare it again.']);
        }
    }
}
