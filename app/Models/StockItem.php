<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockItem extends Model
{
    use Auditable, BelongsToCompany;

    public const TRACKING = [
        'none' => 'No batches or serial numbers',
        'batch' => 'Batch / lot numbers',
        'serial' => 'Serial numbers (one per unit)',
    ];

    public function tracksBatches(): bool
    {
        return $this->tracking === 'batch';
    }

    public function tracksSerials(): bool
    {
        return $this->tracking === 'serial';
    }

    protected $fillable = [
        'company_id', 'name', 'alias', 'code', 'stock_group_id', 'stock_category_id', 'unit_id', 'hsn_sac', 'gst_rate', 'cess_rate',
        'sales_account_id', 'purchase_account_id', 'sales_rate', 'purchase_rate', 'opening_quantity', 'opening_value',
        'opening_godown_id', 'reorder_level', 'is_active', 'description', 'tracking', 'track_expiry',
        'opening_batch_no', 'opening_mfg_date', 'opening_expiry_date', 'opening_serials',
    ];

    protected function casts(): array
    {
        return [
            'gst_rate' => 'decimal:2',
            'cess_rate' => 'decimal:2',
            'sales_rate' => 'decimal:2',
            'purchase_rate' => 'decimal:2',
            'opening_quantity' => 'decimal:3',
            'opening_value' => 'decimal:2',
            'reorder_level' => 'decimal:3',
            'is_active' => 'boolean',
            'track_expiry' => 'boolean',
            'opening_mfg_date' => 'date',
            'opening_expiry_date' => 'date',
        ];
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(StockGroup::class, 'stock_group_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(StockCategory::class, 'stock_category_id');
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function salesAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'sales_account_id');
    }

    public function purchaseAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'purchase_account_id');
    }

    public function openingGodown(): BelongsTo
    {
        return $this->belongsTo(Godown::class, 'opening_godown_id');
    }

    /** True when any document moves this item. */
    public function hasMovements(): bool
    {
        return InvoiceItem::query()->where('stock_item_id', $this->id)->exists()
            || StockJournalLine::query()->where('stock_item_id', $this->id)->exists()
            || DeliveryNoteItem::query()->where('stock_item_id', $this->id)->exists();
    }
}
