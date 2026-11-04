<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Voucher extends Model
{
    use Audited;

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

    protected static function booted(): void
    {
        // Keep the financial year in step with the date; numbering is per year.
        static::saving(function (Voucher $voucher) {
            $voucher->fy_start_year = self::fyStartYear($voucher->date);
        });
    }

    public static function fyStartYear(mixed $date): int
    {
        $date = CarbonImmutable::parse($date);

        return $date->month >= 4 ? $date->year : $date->year - 1;
    }

    /**
     * Next number for a voucher type in the financial year of $date. Call inside a transaction.
     */
    public static function nextNumber(Company $company, string $type, mixed $date): int
    {
        return (int) $company->vouchers()
            ->where('type', $type)
            ->where('fy_start_year', self::fyStartYear($date))
            ->lockForUpdate()
            ->max('number') + 1;
    }

    /**
     * e.g. "2026-27" for the voucher's financial year.
     */
    public function financialYearLabel(): string
    {
        $start = $this->fy_start_year ?? self::fyStartYear($this->date);

        return sprintf('%d-%02d', $start, ($start + 1) % 100);
    }

    public function auditLabel(): string
    {
        return $this->typeLabel().' #'.$this->number.' ('.inr($this->amount).')';
    }
}
