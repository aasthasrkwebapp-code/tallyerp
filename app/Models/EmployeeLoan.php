<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A loan or salary advance paid to an employee and recovered in monthly
 * instalments through payroll. Only recoveries in posted payroll count.
 */
class EmployeeLoan extends Model
{
    use Auditable, BelongsToCompany;

    public const KINDS = ['loan' => 'Loan', 'advance' => 'Salary advance'];

    protected $fillable = ['company_id', 'employee_id', 'date', 'kind', 'amount', 'installment', 'recover_from', 'account_id', 'paid_account_id',
        'voucher_id', 'status', 'description', 'cancellation_reason', 'created_by'];

    protected function casts(): array
    {
        return ['date' => \App\Casts\DateOnly::class, 'amount' => 'decimal:2', 'installment' => 'decimal:2'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function paidAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'paid_account_id');
    }

    public function voucher(): BelongsTo
    {
        return $this->belongsTo(Voucher::class);
    }

    public function recoveries(): HasMany
    {
        return $this->hasMany(LoanRecovery::class);
    }

    /** Recovered in posted payroll, in paise, optionally leaving out one payroll run. */
    public function recoveredPaise(?int $exceptRunId = null): int
    {
        return (int) LoanRecovery::query()->where('employee_loan_id', $this->id)
            ->whereHas('payslip.run', fn ($q) => $q->where('status', 'posted')->when($exceptRunId, fn ($q) => $q->whereKeyNot($exceptRunId)))
            ->get()->sum(fn ($r) => Money::toPaise($r->amount));
    }

    public function outstandingPaise(?int $exceptRunId = null): int
    {
        return Money::toPaise($this->amount) - $this->recoveredPaise($exceptRunId);
    }

    public function auditLabel(): string
    {
        return self::KINDS[$this->kind].' #'.$this->id.' to '.($this->employee?->name ?? 'employee');
    }
}
