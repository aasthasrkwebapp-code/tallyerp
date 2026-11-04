<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VoucherGstLine extends Model
{
    protected $fillable = [
        'voucher_id',
        'account_id',
        'stock_item_id',
        'quantity',
        'item_rate',
        'description',
        'hsn_sac',
        'taxable',
        'rate',
        'cgst',
        'sgst',
        'igst',
        'itc_eligible',
    ];

    protected function casts(): array
    {
        return [
            'taxable' => 'decimal:2',
            'rate' => 'decimal:2',
            'cgst' => 'decimal:2',
            'sgst' => 'decimal:2',
            'igst' => 'decimal:2',
            'itc_eligible' => 'boolean',
        ];
    }

    public function voucher(): BelongsTo
    {
        return $this->belongsTo(Voucher::class);
    }

    public function stockItem(): BelongsTo
    {
        return $this->belongsTo(StockItem::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function tax(): float
    {
        return round((float) $this->cgst + (float) $this->sgst + (float) $this->igst, 2);
    }
}
