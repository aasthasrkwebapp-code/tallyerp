<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccountingPeriod extends Model
{
    use Auditable, BelongsToCompany;

    protected $fillable = [
        'company_id', 'financial_year_id', 'name', 'start_date', 'end_date',
        'is_locked', 'locked_at', 'locked_by',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'is_locked' => 'boolean',
            'locked_at' => 'datetime',
        ];
    }

    public function financialYear(): BelongsTo
    {
        return $this->belongsTo(FinancialYear::class);
    }
}
