<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/** A kind of leave: paid or without pay, with an optional allowance per financial year. */
class LeaveType extends Model
{
    use Auditable, BelongsToCompany;

    protected $fillable = ['company_id', 'name', 'code', 'is_paid', 'annual_days', 'is_active'];

    protected function casts(): array
    {
        return ['is_paid' => 'boolean', 'annual_days' => 'decimal:1', 'is_active' => 'boolean'];
    }

    public function auditLabel(): string
    {
        return 'Leave type '.$this->name;
    }
}
