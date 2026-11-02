<?php

namespace App\Services\Payroll;

use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\EmployeeLoan;
use App\Models\LoanRecovery;
use App\Models\User;
use App\Models\Voucher;
use App\Models\VoucherType;
use App\Services\Accounting\VoucherService;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Loans and salary advances to employees.
 *
 * Paying one out posts a payment voucher: debit the loan ledger (an asset,
 * Salary Advances by default), credit cash or bank. Payroll then deducts the
 * monthly instalment, crediting the loan ledger, until it is repaid.
 */
class LoanService
{
    public function __construct(protected VoucherService $vouchers) {}

    /** Asset ledgers a loan can sit in (not cash or bank, parties or stock). */
    public function loanLedgerQuery(): Builder
    {
        return Account::query()->where('is_active', true)->whereNotIn('account_type', ['cash', 'bank', 'party', 'sales', 'purchase'])
            ->whereHas('group', fn ($q) => $q->where('nature', 'asset'));
    }

    public function paidFromQuery(): Builder
    {
        return Account::query()->where('is_active', true)->whereIn('account_type', ['cash', 'bank']);
    }

    /**
     * @param  array{employee_id: int, kind: string, date: string, amount: string, installment: string, recover_from: string,
     *               account_id?: ?int, paid_account_id: int, description?: ?string}  $data
     */
    public function create(array $data, User $user): EmployeeLoan
    {
        $errors = [];
        $employee = Employee::query()->where('is_active', true)->find($data['employee_id'] ?? 0);
        if (! $employee) {
            $errors['employee_id'] = 'Choose an active employee.';
        }
        if (! array_key_exists($data['kind'] ?? '', EmployeeLoan::KINDS)) {
            $errors['kind'] = 'Choose loan or salary advance.';
        }
        $amount = Money::toPaise($data['amount'] ?? 0);
        $installment = Money::toPaise($data['installment'] ?? 0);
        if ($amount <= 0) {
            $errors['amount'] = 'Enter an amount greater than zero.';
        }
        if ($installment <= 0 || $installment > $amount) {
            $errors['installment'] = 'The monthly instalment must be more than zero and not more than the amount.';
        }
        if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string) ($data['recover_from'] ?? '')) || ($data['recover_from'] ?? '') < substr((string) $data['date'], 0, 7)) {
            $errors['recover_from'] = 'Choose the month recovery starts, not before the month it is paid.';
        }
        $account = ! empty($data['account_id']) ? $this->loanLedgerQuery()->find($data['account_id']) : Account::query()->find(Account::idFor('salary_advances'));
        if (! $account) {
            $errors['account_id'] = 'Choose the asset ledger for the loan, such as Salary Advances.';
        }
        $paid = $this->paidFromQuery()->find($data['paid_account_id'] ?? 0);
        if (! $paid) {
            $errors['paid_account_id'] = 'Choose the cash or bank ledger it is paid from.';
        }
        if ($employee && $data['date'] < $employee->date_of_joining->toDateString()) {
            $errors['date'] = 'The date is before the employee joined.';
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        return DB::transaction(function () use ($data, $user, $employee, $amount, $account, $paid) {
            $loan = EmployeeLoan::create([
                'employee_id' => $employee->id,
                'kind' => $data['kind'],
                'date' => $data['date'],
                'amount' => Money::fromPaise($amount),
                'installment' => Money::normalize($data['installment']),
                'recover_from' => $data['recover_from'],
                'account_id' => $account->id,
                'paid_account_id' => $paid->id,
                'status' => 'active',
                'description' => isset($data['description']) ? mb_substr(trim((string) $data['description']), 0, 255) ?: null : null,
                'created_by' => $user->id,
            ]);

            try {
                $voucher = $this->vouchers->create([
                    'voucher_type_id' => VoucherType::forBase('payment')?->id,
                    'date' => $data['date'],
                    'narration' => EmployeeLoan::KINDS[$loan->kind].' to '.$employee->name.' ('.$employee->code.')'.($loan->description ? ' · '.$loan->description : ''),
                    'entries' => [
                        ['account_id' => $account->id, 'side' => 'Dr', 'amount' => Money::fromPaise($amount)],
                        ['account_id' => $paid->id, 'side' => 'Cr', 'amount' => Money::fromPaise($amount)],
                    ],
                ], $user, Voucher::POSTED, $loan);
            } catch (ValidationException $e) {
                throw ValidationException::withMessages(collect($e->errors())->mapWithKeys(fn ($m, $k) => [str_starts_with($k, 'date') ? 'date' : 'amount' => $m])->all());
            }
            $loan->update(['voucher_id' => $voucher->id]);

            return $loan->fresh('voucher');
        });
    }

    /** Cancel a loan entered by mistake: only while nothing has been recovered in draft or posted payroll. */
    public function cancel(EmployeeLoan $loan, User $user, string $reason): EmployeeLoan
    {
        if ($loan->status !== 'active') {
            throw ValidationException::withMessages(['loan' => 'This loan is already cancelled.']);
        }
        if (LoanRecovery::query()->where('employee_loan_id', $loan->id)->whereHas('payslip.run', fn ($q) => $q->where('status', '!=', 'cancelled'))->exists()) {
            throw ValidationException::withMessages(['loan' => 'Payroll already recovers this loan. Cancel or delete that payroll first.']);
        }

        return DB::transaction(function () use ($loan, $user, $reason) {
            if ($loan->voucher && ! $loan->voucher->isCancelled()) {
                $this->vouchers->cancel($loan->voucher, $user, $reason);
            }
            $loan->update(['status' => 'cancelled', 'cancellation_reason' => mb_substr($reason, 0, 255)]);
            AuditLog::record('cancelled', $loan, null, null, $loan->auditLabel().' cancelled: '.$reason);

            return $loan;
        });
    }

    /**
     * Instalments due from an employee for a payroll month, oldest loan first:
     * loan id => paise. Outstanding counts recoveries in posted payroll other than this run.
     *
     * @return array<int, int>
     */
    public function dueFor(Employee $employee, string $month, ?int $runId = null): array
    {
        $due = [];
        $loans = EmployeeLoan::query()->where('employee_id', $employee->id)->where('status', 'active')
            ->where('recover_from', '<=', $month)->orderBy('date')->orderBy('id')->get();
        foreach ($loans as $loan) {
            $outstanding = $loan->outstandingPaise($runId);
            if ($outstanding > 0) {
                $due[$loan->id] = min(Money::toPaise($loan->installment), $outstanding);
            }
        }

        return $due;
    }

    /** Share $paise over the loans due, oldest first. */
    public function allocate(array $due, int $paise): array
    {
        $out = [];
        foreach ($due as $loanId => $amount) {
            $take = min($amount, $paise);
            if ($take > 0) {
                $out[$loanId] = $take;
            }
            $paise -= $take;
        }

        return $out;
    }

    /** Loans with recovered and outstanding amounts in paise. */
    public function summary(Collection $loans): Collection
    {
        return $loans->map(function (EmployeeLoan $loan) {
            $recovered = $loan->recoveredPaise();

            return (object) [
                'loan' => $loan,
                'amount' => Money::toPaise($loan->amount),
                'recovered' => $recovered,
                'outstanding' => $loan->status === 'active' ? Money::toPaise($loan->amount) - $recovered : 0,
            ];
        });
    }
}
