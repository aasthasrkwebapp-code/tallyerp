<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Money received from a customer (sales order) or paid to a supplier (purchase order) before
 * the invoice. Posted as a receipt or payment voucher; on a bill-wise party it opens an
 * "Advance" bill under bill_ref, later settled against the order's invoices.
 */
class OrderAdvance extends Model
{
    use Auditable, BelongsToCompany;

    protected $fillable = ['company_id', 'order_id', 'date', 'amount', 'bill_ref', 'account_id', 'voucher_id', 'status', 'cancellation_reason', 'created_by'];

    protected function casts(): array
    {
        return ['date' => \App\Casts\DateOnly::class, 'amount' => 'decimal:2'];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function voucher(): BelongsTo
    {
        return $this->belongsTo(Voucher::class);
    }

    public function adjustments(): HasMany
    {
        return $this->hasMany(OrderAdvanceAdjustment::class);
    }

    public function isPosted(): bool
    {
        return $this->status === 'posted';
    }

    public function auditLabel(): string
    {
        return 'Advance '.($this->bill_ref ?? '#'.$this->id);
    }
}
