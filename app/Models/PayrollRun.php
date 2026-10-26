<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/** One month's payroll: payslips for each employee and, once posted, a journal voucher. */
class PayrollRun extends Model
{
    use Auditable, BelongsToCompany;

    protected $fillable = ['company_id', 'month', 'date', 'status', 'voucher_id', 'gross_total', 'deduction_total', 'employer_total', 'net_total', 'narration', 'cancellation_reason', 'created_by'];

    protected function casts(): array
    {
        return [
            'date' => \App\Casts\DateOnly::class,
            'gross_total' => 'decimal:2', 'deduction_total' => 'decimal:2', 'employer_total' => 'decimal:2', 'net_total' => 'decimal:2',
        ];
    }

    public function payslips(): HasMany
    {
        return $this->hasMany(Payslip::class);
    }

    public function voucher(): BelongsTo
    {
        return $this->belongsTo(Voucher::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function monthStart(): Carbon
    {
        return Carbon::createFromFormat('Y-m-d', $this->month.'-01')->startOfDay();
    }

    public function label(): string
    {
        return $this->monthStart()->format('F Y');
    }

    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }

    public function auditLabel(): string
    {
        return 'Payroll '.$this->label();
    }
}
