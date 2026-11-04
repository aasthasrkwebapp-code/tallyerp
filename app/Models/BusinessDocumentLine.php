<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BusinessDocumentLine extends Model
{
    protected $fillable = [
        'business_document_id',
        'account_id',
        'stock_item_id',
        'description',
        'hsn_sac',
        'quantity',
        'item_rate',
        'gross_amount',
        'discount_percent',
        'taxable',
        'rate',
        'tax',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'item_rate' => 'decimal:2',
            'gross_amount' => 'decimal:2',
            'discount_percent' => 'decimal:2',
            'taxable' => 'decimal:2',
            'rate' => 'decimal:2',
            'tax' => 'decimal:2',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function stockItem(): BelongsTo
    {
        return $this->belongsTo(StockItem::class);
    }
}
