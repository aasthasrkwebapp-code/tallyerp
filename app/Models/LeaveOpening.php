<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** An employee's leave balance of one type as on the books' beginning. */
class LeaveOpening extends Model
{
    use Auditable, BelongsToCompany;

    protected $fillable = ['company_id', 'employee_id', 'leave_type_id', 'days'];

    protected function casts(): array
    {
        return ['days' => 'decimal:1'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class);
    }

    public function auditLabel(): string
    {
        return 'Opening leave of '.($this->employee?->name ?? 'employee');
    }
}
