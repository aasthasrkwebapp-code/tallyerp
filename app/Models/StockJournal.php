<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Stock transfer between godowns, or a stock adjustment (shortage, excess,
 * damage, consumption). Moves quantity only; it has no ledger entries, and its
 * effect on profit comes through the closing stock value.
 */
class StockJournal extends Model
{
    use Auditable, BelongsToCompany;

    public const PURPOSES = ['adjustment' => 'Stock adjustment', 'transfer' => 'Godown transfer', 'production' => 'Manufacture / consumption'];

    protected $fillable = ['company_id', 'number', 'date', 'purpose', 'transit_to_godown_id', 'dispatch_id', 'dispatch_invoice_id', 'status', 'narration', 'cancellation_reason', 'created_by', 'cancelled_by', 'cancelled_at'];

    protected function casts(): array
    {
        return ['date' => \App\Casts\DateOnly::class, 'cancelled_at' => 'datetime'];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(StockJournalLine::class);
    }

    /** Where goods dispatched into transit are bound. */
    public function transitTo(): BelongsTo
    {
        return $this->belongsTo(Godown::class, 'transit_to_godown_id');
    }

    /** The dispatch a receipt or shortage of goods in transit settles. */
    public function dispatch(): BelongsTo
    {
        return $this->belongsTo(StockJournal::class, 'dispatch_id');
    }

    /** The purchase bill in transit that a receipt or shortage settles. */
    public function dispatchInvoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'dispatch_invoice_id');
    }

    /** Receipts and shortages against this dispatch. */
    public function settlements(): HasMany
    {
        return $this->hasMany(StockJournal::class, 'dispatch_id');
    }

    public function isDispatch(): bool
    {
        return $this->transit_to_godown_id !== null;
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isPosted(): bool
    {
        return $this->status === 'posted';
    }

    public function auditLabel(): string
    {
        return 'Stock journal '.$this->number;
    }
}
