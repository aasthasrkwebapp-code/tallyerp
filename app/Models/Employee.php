<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Employee extends Model
{
    use Auditable, BelongsToCompany;

    protected $fillable = [
        'company_id', 'code', 'name', 'designation', 'department', 'date_of_joining', 'date_of_leaving', 'date_of_birth', 'gender',
        'pan', 'uan', 'esi_number', 'bank_name', 'bank_account', 'ifsc', 'phone', 'email', 'address',
        'pf_applicable', 'esi_applicable', 'pt_applicable', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'date_of_joining' => \App\Casts\DateOnly::class,
            'date_of_leaving' => \App\Casts\DateOnly::class,
            'date_of_birth' => \App\Casts\DateOnly::class,
            'pf_applicable' => 'boolean',
            'esi_applicable' => 'boolean',
            'pt_applicable' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /** Salary structure: the pay heads that apply, with monthly amounts for flat heads. */
    public function payHeads(): HasMany
    {
        return $this->hasMany(EmployeePayHead::class);
    }

    public function payslips(): HasMany
    {
        return $this->hasMany(Payslip::class);
    }

    public function loans(): HasMany
    {
        return $this->hasMany(EmployeeLoan::class);
    }

    public function attendance(): HasMany
    {
        return $this->hasMany(AttendanceDay::class);
    }

    public function auditLabel(): string
    {
        return 'Employee '.$this->code.' '.$this->name;
    }

    public function leaveOpenings(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(LeaveOpening::class);
    }

    public function leaveEncashments(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(LeaveEncashment::class);
    }
}
