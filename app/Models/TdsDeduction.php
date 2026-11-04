<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TdsDeduction extends Model
{
    protected $fillable = [
        'company_id',
        'voucher_id',
        'party_account_id',
        'section',
        'base_amount',
        'rate',
        'tds_amount',
    ];

    protected function casts(): array
    {
        return [
            'base_amount' => 'decimal:2',
            'rate' => 'decimal:2',
            'tds_amount' => 'decimal:2',
        ];
    }

    public function voucher(): BelongsTo
    {
        return $this->belongsTo(Voucher::class);
    }

    public function party(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'party_account_id');
    }
}
