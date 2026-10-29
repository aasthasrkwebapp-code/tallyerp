<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class PayslipLine extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'payslip_id', 'pay_head_id', 'name', 'type', 'amount'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2'];
    }
}
