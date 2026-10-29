<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Unused leave paid out to an employee by a payment voucher. */
class LeaveEncashment extends Model
{
    use Auditable, BelongsToCompany;

    protected $fillable = ['company_id', 'employee_id', 'leave_type_id', 'date', 'days', 'rate', 'amount', 'expense_account_id', 'paid_account_id',
        'voucher_id', 'status', 'cancellation_reason', 'narration', 'created_by'];

    protected function casts(): array
    {
        return ['date' => \App\Casts\DateOnly::class, 'days' => 'decimal:1', 'rate' => 'decimal:2', 'amount' => 'decimal:2'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class);
    }

    public function voucher(): BelongsTo
    {
        return $this->belongsTo(Voucher::class);
    }

    public function paidAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'paid_account_id');
    }

    public function auditLabel(): string
    {
        return 'Leave encashment of '.($this->employee?->name ?? 'employee');
    }
}
