<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VoucherEntry extends Model
{
    protected $fillable = [
        'voucher_id',
        'account_id',
        'cost_centre_id',
        'debit',
        'credit',
        'currency_code',
        'foreign_amount',
        'exchange_rate',
        'instrument_no',
        'bank_date',
    ];

    protected function casts(): array
    {
        return [
            'debit' => 'decimal:2',
            'credit' => 'decimal:2',
            'foreign_amount' => 'decimal:2',
            'exchange_rate' => 'decimal:6',
            'bank_date' => 'date',
        ];
    }

    public function voucher(): BelongsTo
    {
        return $this->belongsTo(Voucher::class);
    }

    public function costCentre(): BelongsTo
    {
        return $this->belongsTo(CostCentre::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
