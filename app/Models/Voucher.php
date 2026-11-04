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

    public function typeLabel(): string
    {
        return self::TYPES[$this->type] ?? $this->type;
    }
}
