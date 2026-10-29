<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RecurringVoucherLine extends Model
{
    use BelongsToCompany;

    public $timestamps = false;

    protected $fillable = ['company_id', 'recurring_voucher_id', 'line_no', 'account_id', 'side', 'amount', 'cost_centre_id', 'narration', 'bill_type', 'due_days'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2'];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function costCentre(): BelongsTo
    {
        return $this->belongsTo(CostCentre::class);
    }
}
