<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SerialNumber extends Model
{
    protected $fillable = [
        'company_id',
        'stock_item_id',
        'serial',
        'in_voucher_id',
        'out_voucher_id',
    ];

    public function stockItem(): BelongsTo
    {
        return $this->belongsTo(StockItem::class);
    }

    public function inVoucher(): BelongsTo
    {
        return $this->belongsTo(Voucher::class, 'in_voucher_id');
    }

    public function outVoucher(): BelongsTo
    {
        return $this->belongsTo(Voucher::class, 'out_voucher_id');
    }

    public function inStock(): bool
    {
        return $this->out_voucher_id === null;
    }

    /**
     * "SN1, SN2" / one per line → ['SN1', 'SN2'] (trimmed, upper-case, de-duplicated).
     *
     * @return array<int, string>
     */
    public static function parse(?string $text): array
    {
        return array_values(array_unique(array_filter(array_map(
            fn ($s) => strtoupper(trim($s)),
            preg_split('/[\s,;]+/', (string) $text),
        ))));
    }
}
