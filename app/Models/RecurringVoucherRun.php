<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RecurringVoucherRun extends Model
{
    use BelongsToCompany;

    public const UPDATED_AT = null;

    protected $fillable = ['company_id', 'recurring_voucher_id', 'date', 'voucher_id', 'status', 'message', 'run_by'];

    protected function casts(): array
    {
        return ['date' => \App\Casts\DateOnly::class];
    }

    public function recurring(): BelongsTo
    {
        return $this->belongsTo(RecurringVoucher::class, 'recurring_voucher_id');
    }

    public function voucher(): BelongsTo
    {
        return $this->belongsTo(Voucher::class);
    }

    public function runner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'run_by');
    }
}
