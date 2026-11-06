<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockMovement extends Model
{
    protected $fillable = [
        'company_id',
        'voucher_id',
        'production_id',
        'stock_item_id',
        'stock_batch_id',
        'godown_id',
        'stock_transfer_id',
        'date',
        'quantity_in',
        'quantity_out',
        'rate',
        'amount',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'quantity_in' => 'decimal:3',
            'quantity_out' => 'decimal:3',
            'rate' => 'decimal:2',
            'amount' => 'decimal:2',
        ];
    }

    public function voucher(): BelongsTo
    {
        return $this->belongsTo(Voucher::class);
    }

    public function godown(): BelongsTo
    {
        return $this->belongsTo(Godown::class);
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(StockBatch::class, 'stock_batch_id');
    }

    public function stockItem(): BelongsTo
    {
        return $this->belongsTo(StockItem::class);
    }
}
