<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** An employee's tax regime and declared exemptions and deductions for one financial year. */
class TaxDeclaration extends Model
{
    use Auditable, BelongsToCompany;

    public const REGIMES = ['new' => 'New regime (section 115BAC)', 'old' => 'Old regime'];

    /** Amount fields => label. */
    public const AMOUNTS = [
        'hra_exemption' => 'HRA exemption, section 10(13A)',
        'other_exemptions' => 'Other exemptions under section 10 (LTA etc.)',
        'home_loan_interest' => 'Interest on home loan, section 24(b)',
        'other_income' => 'Other income declared by the employee',
        'section_80c' => 'Section 80C (PF, LIC, PPF, ELSS, tuition fees, principal on home loan)',
        'section_80ccd_1b' => 'Section 80CCD(1B), NPS',
        'section_80d' => 'Section 80D, medical insurance',
        'other_deductions' => 'Other Chapter VI-A deductions (80E, 80G, 80TTA …)',
        'previous_employer_salary' => 'Salary from a previous employer this year',
        'previous_employer_tds' => 'TDS by the previous employer',
    ];

    /** Fields that count only under the old regime. */
    public const OLD_REGIME_ONLY = ['hra_exemption', 'other_exemptions', 'home_loan_interest', 'section_80c', 'section_80ccd_1b', 'section_80d', 'other_deductions'];

    protected $fillable = ['company_id', 'employee_id', 'financial_year_id', 'regime', ...self::FILLABLE_AMOUNTS];

    protected const FILLABLE_AMOUNTS = ['hra_exemption', 'other_exemptions', 'home_loan_interest', 'other_income', 'section_80c', 'section_80ccd_1b', 'section_80d', 'other_deductions', 'previous_employer_salary', 'previous_employer_tds'];

    protected function casts(): array
    {
        return array_fill_keys(self::FILLABLE_AMOUNTS, 'decimal:2');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function financialYear(): BelongsTo
    {
        return $this->belongsTo(FinancialYear::class);
    }

    public function auditLabel(): string
    {
        return 'Tax declaration '.$this->employee?->code.' '.$this->financialYear?->name;
    }
}
