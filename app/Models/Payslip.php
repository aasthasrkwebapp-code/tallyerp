<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Payslip extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'payroll_run_id', 'employee_id', 'employee_code', 'employee_name', 'designation', 'days_in_month', 'paid_days', 'gross', 'deductions', 'employer_contributions', 'net'];

    protected function casts(): array
    {
        return ['paid_days' => 'decimal:1', 'gross' => 'decimal:2', 'deductions' => 'decimal:2', 'employer_contributions' => 'decimal:2', 'net' => 'decimal:2'];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(PayrollRun::class, 'payroll_run_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PayslipLine::class);
    }
}
