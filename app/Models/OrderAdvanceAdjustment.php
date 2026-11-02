<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** An advance set off against an invoice of its order, by a journal that settles both bills. */
class OrderAdvanceAdjustment extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'order_advance_id', 'invoice_id', 'date', 'amount', 'voucher_id', 'status', 'created_by'];

    protected function casts(): array
    {
        return ['date' => \App\Casts\DateOnly::class, 'amount' => 'decimal:2'];
    }

    public function advance(): BelongsTo
    {
        return $this->belongsTo(OrderAdvance::class, 'order_advance_id');
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function voucher(): BelongsTo
    {
        return $this->belongsTo(Voucher::class);
    }
}
