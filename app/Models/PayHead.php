<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An earning, deduction or employer contribution on the payslip.
 *
 * Calculations: flat (monthly amount per employee), percent_of_basic,
 * pf (rate % of basic up to the PF wage ceiling), esi (rate % of gross while
 * gross is within the ESI limit), pt (state professional tax slab), overtime
 * (the month's overtime hours × the employee's hourly rate) and loan (the
 * instalments due on the employee's loans and advances).
 */
class PayHead extends Model
{
    use Auditable, BelongsToCompany;

    public const TYPES = ['earning' => 'Earning', 'deduction' => 'Deduction', 'employer' => 'Employer contribution'];

    public const CALCULATIONS = [
        'flat' => 'Fixed monthly amount',
        'percent_of_basic' => '% of basic',
        'pf' => 'Provident fund (% of basic, capped)',
        'esi' => 'ESI (% of gross, within limit)',
        'pt' => 'Professional tax (state slab)',
        'overtime' => 'Overtime (hours × hourly rate)',
        'loan' => 'Loan / advance recovery',
    ];

    protected $fillable = ['company_id', 'system_key', 'name', 'type', 'calculation', 'rate', 'is_basic', 'prorate', 'account_id', 'payable_account_id', 'sort_order', 'is_active'];

    protected function casts(): array
    {
        return ['rate' => 'decimal:3', 'is_basic' => 'boolean', 'prorate' => 'boolean', 'is_active' => 'boolean'];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function payableAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'payable_account_id');
    }

    public function auditLabel(): string
    {
        return 'Pay head '.$this->name;
    }
}
