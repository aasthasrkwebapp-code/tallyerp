<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payslip extends Model
{
    public const MONEY = [
        'basic', 'hra', 'other_allowances', 'gross',
        'pf_employee', 'esi_employee', 'professional_tax', 'net_pay',
        'pf_employer', 'esi_employer',
    ];

    protected $fillable = [
        'payroll_run_id',
        'employee_id',
        'days_payable',
        'days_in_month',
        ...self::MONEY,
    ];

    protected function casts(): array
    {
        return array_fill_keys(self::MONEY, 'decimal:2') + [
            'days_payable' => 'decimal:2',
            'days_in_month' => 'decimal:2',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(PayrollRun::class, 'payroll_run_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function totalDeductions(): float
    {
        return round((float) $this->pf_employee + (float) $this->esi_employee + (float) $this->professional_tax, 2);
    }
}
