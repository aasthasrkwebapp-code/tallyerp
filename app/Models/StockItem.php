<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockItem extends Model
{
    use Auditable, BelongsToCompany;

    protected $fillable = [
        'company_id', 'name', 'alias', 'code', 'stock_group_id', 'stock_category_id', 'unit_id', 'hsn_sac', 'gst_rate',
        'sales_account_id', 'purchase_account_id', 'sales_rate', 'purchase_rate', 'opening_quantity', 'opening_value',
        'opening_godown_id', 'reorder_level', 'is_active', 'description',
    ];

    protected function casts(): array
    {
        return [
            'gst_rate' => 'decimal:2',
            'sales_rate' => 'decimal:2',
            'purchase_rate' => 'decimal:2',
            'opening_quantity' => 'decimal:3',
            'opening_value' => 'decimal:2',
            'reorder_level' => 'decimal:3',
            'is_active' => 'boolean',
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
            || StockJournalLine::query()->where('stock_item_id', $this->id)->exists();
    }
}
