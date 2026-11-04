<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Employee extends Model
{
    use Audited;

    protected $fillable = [
        'company_id',
        'code',
        'name',
        'designation',
        'pan',
        'uan',
        'joined_on',
        'basic',
        'hra',
        'other_allowances',
        'pf_applicable',
        'esi_applicable',
        'pt_applicable',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'joined_on' => 'date',
            'basic' => 'decimal:2',
            'hra' => 'decimal:2',
            'other_allowances' => 'decimal:2',
            'pf_applicable' => 'boolean',
            'esi_applicable' => 'boolean',
            'pt_applicable' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function payslips(): HasMany
    {
        return $this->hasMany(Payslip::class);
    }

    public function monthlyGross(): float
    {
        return round((float) $this->basic + (float) $this->hra + (float) $this->other_allowances, 2);
    }

    public function auditLabel(): string
    {
        return 'Employee '.$this->name;
    }
}
