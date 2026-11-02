<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeePayHead extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'employee_id', 'pay_head_id', 'amount'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2'];
    }

    public function payHead(): BelongsTo
    {
        return $this->belongsTo(PayHead::class);
    }
}
