<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StockBatch extends Model
{
    /** Batches expiring within this many days are flagged. */
    public const EXPIRY_WARNING_DAYS = 30;

    protected $fillable = [
        'company_id',
        'stock_item_id',
        'batch_no',
        'mfg_date',
        'expiry_date',
    ];

    protected function casts(): array
    {
        return [
            'mfg_date' => 'date',
            'expiry_date' => 'date',
        ];
    }

    public function stockItem(): BelongsTo
    {
        return $this->belongsTo(StockItem::class);
    }

    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    /**
     * Quantity in hand, optionally excluding one voucher's movements (when re-checking an edit).
     */
    public function balance(): float
    {
        return round((float) $this->movements()->sum('quantity_in') - (float) $this->movements()->sum('quantity_out'), 3);
    }

    /**
     * expired | expiring | ok | null (no expiry date)
     */
    public function expiryStatus(?\DateTimeInterface $on = null): ?string
    {
        if (! $this->expiry_date) {
            return null;
        }

        $on = \Carbon\CarbonImmutable::instance($on ?? now())->startOfDay();

        return match (true) {
            $this->expiry_date->lt($on) => 'expired',
            $this->expiry_date->lte($on->addDays(self::EXPIRY_WARNING_DAYS)) => 'expiring',
            default => 'ok',
        };
    }
}
