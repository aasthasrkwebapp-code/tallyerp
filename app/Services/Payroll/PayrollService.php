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
        protected AttendanceService $attendance,
        protected LoanService $loans,
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
            $register = $this->attendance->register($month, $employees)->keyBy(fn ($r) => $r->employee->id);

            foreach ($employees as $employee) {
                // Paid days from the attendance register when the month is marked, else the days on the rolls
                // (joining or leaving mid-month).
                $att = $register[$employee->id];
                $paid = $att->marked ? $att->paid_days : (string) ($att->on_rolls[1] - $att->on_rolls[0] + 1);
                $this->writePayslip($run, $employee, $days, $paid, $att->overtime, $att->marked);
            }

            return $this->refreshTotals($run);
        });
    }

    /**
     * Set paid days (employee id => days) on a draft run and recalculate.
     * Also picks up salary structure changes made since the run was prepared.
     */
    public function recalculate(PayrollRun $run, array $paidDays, bool $fromAttendance = false): PayrollRun
    {
        $this->assertDraft($run);
        $run->load('payslips');
        $register = $this->attendance->register($run->month, Employee::query()->whereIn('id', $run->payslips->pluck('employee_id'))->orderBy('code')->get())
            ->keyBy(fn ($r) => $r->employee->id);
        if ($fromAttendance) {
            $paidDays = $register->map(fn ($r) => $r->paid_days)->all();
        }
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

        return DB::transaction(function () use ($run, $paidDays, $register, $fromAttendance) {
            foreach ($run->payslips as $slip) {
                $employee = Employee::query()->with('payHeads')->findOrFail($slip->employee_id);
                $value = trim((string) ($paidDays[$slip->employee_id] ?? Decimal::trim($slip->paid_days)));
                $att = $register[$slip->employee_id];
                // Still "from attendance" while the paid days match the register.
                $fromRegister = $att->marked && ($fromAttendance || $value === $att->paid_days);
                $slip->delete();
                $this->writePayslip($run, $employee, $slip->days_in_month, $value, $att->overtime, $fromRegister);
            }

            return $this->refreshTotals($run);
        });
    }

    public function post(PayrollRun $run, User $user, ?string $date = null): PayrollRun
    {
        $this->assertDraft($run);
        $run->load('payslips.lines', 'payslips.loanRecoveries.loan');
        if ((float) $run->gross_total <= 0) {
            throw ValidationException::withMessages(['run' => 'There is nothing to post: the gross pay is zero.']);
        }
        $type = VoucherType::forBase('journal');
        $salaryPayable = \App\Models\Account::idFor('salary_payable');
        if (! $type || ! $salaryPayable) {
            throw ValidationException::withMessages(['run' => 'The Journal voucher type and the Salary Payable ledger are needed to post payroll.']);
        }

        $this->assertLoansOutstanding($run);

        return DB::transaction(function () use ($run, $user, $date, $type, $salaryPayable) {
            $date ??= $run->date->toDateString();
            $heads = PayHead::query()->whereIn('id', $run->payslips->flatMap->lines->pluck('pay_head_id')->unique())->get()->keyBy('id');
            $debits = [];
            $credits = [];
            // Loan recoveries go back to each loan's own ledger.
            foreach ($run->payslips->flatMap->loanRecoveries as $recovery) {
                $account = $recovery->loan->account_id;
                $credits[$account] = ($credits[$account] ?? 0) + Money::toPaise($recovery->amount);
            }
            foreach ($run->payslips->flatMap->lines as $line) {
                $head = $heads[$line->pay_head_id];
                $amount = Money::toPaise($line->amount);
                if ($amount === 0 || $head->calculation === 'loan') {
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

    /** Cash and bank ledgers salary can be paid from. */
    public function paidFromQuery(): \Illuminate\Database\Eloquent\Builder
    {
        return \App\Models\Account::query()->where('is_active', true)->whereIn('account_type', ['cash', 'bank']);
    }

    /**
     * Record the payment of a posted payroll's net salary: a payment voucher debiting
     * Salary Payable and crediting the chosen cash or bank ledger.
     *
     * @param  array{date: string, paid_account_id: int}  $data
     */
    public function pay(PayrollRun $run, array $data, User $user): PayrollRun
    {
        $run->load('paymentVoucher');
        if ($run->status !== 'posted') {
            throw ValidationException::withMessages(['run' => 'Post the payroll before recording its payment.']);
        }
        if ($run->isPaid()) {
            throw ValidationException::withMessages(['run' => 'This payroll is already paid by voucher '.$run->paymentVoucher->voucher_number.'.']);
        }
        $net = Money::toPaise($run->net_total);
        if ($net <= 0) {
            throw ValidationException::withMessages(['run' => 'There is no net pay to pay.']);
        }
        $errors = [];
        $paid = $this->paidFromQuery()->find($data['paid_account_id'] ?? 0);
        if (! $paid) {
            $errors['paid_account_id'] = 'Choose the cash or bank ledger the salary is paid from.';
        }
        $date = (string) ($data['date'] ?? '');
        if ($date < $run->date->toDateString()) {
            $errors['date'] = 'The payment cannot be dated before the payroll ('.$run->date->format('d M Y').').';
        }
        $salaryPayable = \App\Models\Account::idFor('salary_payable');
        $type = VoucherType::forBase('payment');
        if (! $salaryPayable || ! $type) {
            $errors['run'] = 'The Payment voucher type and the Salary Payable ledger are needed.';
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        return DB::transaction(function () use ($run, $user, $date, $paid, $net, $salaryPayable, $type) {
            try {
                $voucher = $this->vouchers->create([
                    'voucher_type_id' => $type->id,
                    'date' => $date,
                    'narration' => 'Salary paid for '.$run->label().' ('.$run->payslips()->where('net', '>', 0)->count().' employees)',
                    'entries' => [
                        ['account_id' => $salaryPayable, 'side' => 'Dr', 'amount' => Money::fromPaise($net)],
                        ['account_id' => $paid->id, 'side' => 'Cr', 'amount' => Money::fromPaise($net)],
                    ],
                ], $user, Voucher::POSTED, $run);
            } catch (ValidationException $e) {
                throw ValidationException::withMessages(collect($e->errors())->mapWithKeys(fn ($m, $k) => [str_starts_with($k, 'date') ? 'date' : 'run' => $m])->all());
            }
            $run->update(['payment_voucher_id' => $voucher->id]);
            AuditLog::record('updated', $run, null, ['payment_voucher' => $voucher->voucher_number], "Salary of {$run->label()} paid from {$paid->name} by voucher {$voucher->voucher_number}");

            return $run->fresh('paymentVoucher');
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
            // The salary payment goes with it.
            if ($run->paymentVoucher && ! $run->paymentVoucher->isCancelled()) {
                $this->vouchers->cancel($run->paymentVoucher, $user, $reason);
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

    protected function writePayslip(PayrollRun $run, Employee $employee, int $days, string $paidDays, string $overtime = '0', bool $fromAttendance = false): void
    {
        $heads = PayHead::query()->where('is_active', true)->orderBy('sort_order')->orderBy('id')->get();
        $due = $this->loans->dueFor($employee, $run->month, $run->id);
        $result = $this->calculator->calculate($employee, $heads, $days, $paidDays, (int) substr($run->month, 5, 2), $this->current->get()->state,
            $overtime, array_sum($due));

        $slip = $run->payslips()->create([
            'company_id' => $run->company_id,
            'employee_id' => $employee->id,
            'employee_code' => $employee->code,
            'employee_name' => $employee->name,
            'designation' => $employee->designation,
            'days_in_month' => $days,
            'paid_days' => $paidDays,
            'overtime_hours' => $overtime,
            'from_attendance' => $fromAttendance,
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
            if ($line['head']->calculation === 'loan') {
                foreach ($this->loans->allocate($due, $line['amount']) as $loanId => $paise) {
                    $slip->loanRecoveries()->create(['company_id' => $run->company_id, 'employee_loan_id' => $loanId, 'amount' => Money::fromPaise($paise)]);
                }
            }
        }
    }

    /** Another payroll may have recovered the same instalment since this one was prepared. */
    protected function assertLoansOutstanding(PayrollRun $run): void
    {
        foreach ($run->payslips->flatMap->loanRecoveries->groupBy('employee_loan_id') as $recoveries) {
            $loan = $recoveries->first()->loan;
            $taking = $recoveries->sum(fn ($r) => Money::toPaise($r->amount));
            if ($loan->status !== 'active' || $taking > $loan->outstandingPaise($run->id)) {
                throw ValidationException::withMessages(['run' => 'A loan recovered in this payroll has changed since it was prepared. Recalculate, then post.']);
            }
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
