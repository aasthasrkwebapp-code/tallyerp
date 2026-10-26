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

    protected $fillable = ['company_id', 'number', 'date', 'purpose', 'status', 'narration', 'cancellation_reason', 'created_by', 'cancelled_by', 'cancelled_at'];

    protected function casts(): array
    {
        return ['date' => \App\Casts\DateOnly::class, 'cancelled_at' => 'datetime'];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(StockJournalLine::class);
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
