<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Voucher extends Model
{
    public const TYPES = [
        'payment' => 'Payment',
        'receipt' => 'Receipt',
        'contra' => 'Contra',
        'journal' => 'Journal',
        'sales' => 'Sales',
        'purchase' => 'Purchase',
        'debit_note' => 'Debit Note',
        'credit_note' => 'Credit Note',
    ];

    protected $fillable = [
        'company_id',
        'type',
        'number',
        'date',
        'reference',
        'narration',
        'amount',
        'party_account_id',
        'place_of_supply',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'amount' => 'decimal:2',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function entries(): HasMany
    {
        return $this->hasMany(VoucherEntry::class);
    }

    public function gstLines(): HasMany
    {
        return $this->hasMany(VoucherGstLine::class);
    }

    public function tdsDeductions(): HasMany
    {
        return $this->hasMany(TdsDeduction::class);
    }

    public function party(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'party_account_id');
    }

    public function typeLabel(): string
    {
        return self::TYPES[$this->type] ?? $this->type;
    }

    /**
     * Next number for a voucher type. Call inside a transaction.
     */
    public static function nextNumber(Company $company, string $type): int
    {
        return (int) $company->vouchers()
            ->where('type', $type)
            ->lockForUpdate()
            ->max('number') + 1;
    }
}
