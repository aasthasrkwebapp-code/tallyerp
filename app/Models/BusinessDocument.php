<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An estimate, order or challan. It doesn't touch the books; converting it
 * opens a GST invoice pre-filled with its lines.
 */
class BusinessDocument extends Model
{
    use Audited;

    /**
     * type => [label, side (which ledgers and invoice it converts to), converts to invoice type]
     */
    public const TYPES = [
        'estimate' => ['Estimate', 'sales', 'sales'],
        'sale_order' => ['Sale Order', 'sales', 'sales'],
        'purchase_order' => ['Purchase Order', 'purchase', 'purchase'],
        'delivery_challan' => ['Delivery Challan', 'sales', 'sales'],
    ];

    public const STATUSES = ['open' => 'Open', 'converted' => 'Converted', 'cancelled' => 'Cancelled'];

    protected $fillable = [
        'company_id',
        'type',
        'number',
        'fy_start_year',
        'date',
        'valid_until',
        'party_account_id',
        'place_of_supply',
        'reference',
        'notes',
        'amount',
        'status',
        'voucher_id',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'valid_until' => 'date',
            'amount' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        static::saving(fn (BusinessDocument $d) => $d->fy_start_year = Voucher::fyStartYear($d->date));
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function party(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'party_account_id');
    }

    public function voucher(): BelongsTo
    {
        return $this->belongsTo(Voucher::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(BusinessDocumentLine::class);
    }

    public function typeLabel(): string
    {
        return self::TYPES[$this->type][0] ?? $this->type;
    }

    public function side(): string
    {
        return self::TYPES[$this->type][1] ?? 'sales';
    }

    public function convertsTo(): ?string
    {
        return self::TYPES[$this->type][2] ?? null;
    }

    public function numberLabel(): string
    {
        return $this->number.'/'.sprintf('%d-%02d', $this->fy_start_year, ($this->fy_start_year + 1) % 100);
    }

    public static function nextNumber(Company $company, string $type, mixed $date): int
    {
        return (int) $company->businessDocuments()
            ->where('type', $type)
            ->where('fy_start_year', Voucher::fyStartYear($date))
            ->lockForUpdate()
            ->max('number') + 1;
    }

    public function auditLabel(): string
    {
        return $this->typeLabel().' #'.$this->number.' ('.inr($this->amount).')';
    }
}
