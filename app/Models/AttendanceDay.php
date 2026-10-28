<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One employee's attendance on one day. A day that is not marked counts as present.
 */
class AttendanceDay extends Model
{
    use BelongsToCompany;

    /** status => [code shown in the register, label, paid days, leave days] for a full-day status */
    public const STATUSES = [
        'present' => ['P', 'Present', 1, 0],
        'absent' => ['A', 'Absent', 0, 0],
        'half_day' => ['½', 'Half day (half absent)', 0.5, 0],
        'leave' => ['L', 'Leave', null, 1],
        'half_leave' => ['½L', 'Half day leave', null, 0.5],
        'week_off' => ['W', 'Weekly off', 1, 0],
        'holiday' => ['H', 'Holiday', 1, 0],
    ];

    protected $fillable = ['company_id', 'employee_id', 'date', 'status', 'leave_type_id'];

    protected function casts(): array
    {
        return ['date' => \App\Casts\DateOnly::class];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class);
    }
}
