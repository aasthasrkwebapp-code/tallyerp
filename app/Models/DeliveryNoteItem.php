<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeliveryNoteItem extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'delivery_note_id', 'line_no', 'stock_item_id', 'godown_id', 'order_item_id', 'description', 'quantity', 'unit',
        'rate', 'value', 'batch_no', 'mfg_date', 'expiry_date', 'serials',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'rate' => 'decimal:2',
            'value' => 'decimal:2',
            'mfg_date' => 'date',
            'expiry_date' => 'date',
        ];
    }

    /** @return list<string> */
    public function serialList(): array
    {
        return \App\Services\Inventory\BatchTracker::splitSerials($this->serials);
    }

    public function note(): BelongsTo
    {
        return $this->belongsTo(DeliveryNote::class, 'delivery_note_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(StockItem::class, 'stock_item_id');
    }

    public function godown(): BelongsTo
    {
        return $this->belongsTo(Godown::class);
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }
}
