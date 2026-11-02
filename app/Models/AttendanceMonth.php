<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/** Monthly attendance figures for an employee that are not tied to a day: overtime hours. */
class AttendanceMonth extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'employee_id', 'month', 'overtime_hours'];

    protected function casts(): array
    {
        return ['overtime_hours' => 'decimal:2'];
    }
}
