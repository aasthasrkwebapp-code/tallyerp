<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A sales order (from a customer) or purchase order (to a supplier).
 *
 * Stored status is open, closed (short-closed by a user) or cancelled; how
 * far an open order is delivered comes from the posted invoices against it.
 */
class Order extends Model
{
    use Auditable, BelongsToCompany;

    public const OPEN = 'open';
    public const CLOSED = 'closed';
    public const CANCELLED = 'cancelled';

    protected $fillable = [
        'company_id', 'kind', 'source_id', 'number', 'revision', 'date', 'delivery_date', 'party_account_id', 'party_name', 'reference', 'reference_date',
        'place_of_supply', 'tax_mode', 'gross_total', 'discount_total', 'taxable_total', 'cgst_total', 'sgst_total', 'igst_total',
        'grand_total', 'status', 'status_reason', 'status_at', 'status_by', 'narration', 'terms', 'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'date' => \App\Casts\DateOnly::class,
            'delivery_date' => \App\Casts\DateOnly::class,
            'reference_date' => \App\Casts\DateOnly::class,
            'status_at' => 'datetime',
            'gross_total' => 'decimal:2',
            'discount_total' => 'decimal:2',
            'taxable_total' => 'decimal:2',
            'cgst_total' => 'decimal:2',
            'sgst_total' => 'decimal:2',
            'igst_total' => 'decimal:2',
            'grand_total' => 'decimal:2',
        ];
    }

    public static function kinds(): array
    {
        return config('erp.order_kinds');
    }

    public static function kindConfig(string $kind): array
    {
        return config("erp.order_kinds.$kind") ?? throw new \InvalidArgumentException("Unknown order kind {$kind}.");
    }

    public function config(): array
    {
        return static::kindConfig($this->kind);
    }

    public function scopeOfKind(Builder $query, string $kind): Builder
    {
        return $query->where('kind', $kind);
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class)->orderBy('line_no');
    }

    public function party(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'party_account_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function statusUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'status_by');
    }

    public function isQuotation(): bool
    {
        return (bool) ($this->config()['quotation'] ?? false);
    }

    /** The quotation this order was made from. */
    public function source(): BelongsTo
    {
        return $this->belongsTo(self::class, 'source_id');
    }

    /** Orders made from this quotation. */
    public function conversions(): HasMany
    {
        return $this->hasMany(self::class, 'source_id');
    }

    public function advances(): HasMany
    {
        return $this->hasMany(OrderAdvance::class)->orderBy('date')->orderBy('id');
    }

    /** Earlier versions, newest first. */
    public function revisions(): HasMany
    {
        return $this->hasMany(OrderRevision::class)->orderByDesc('revision');
    }

    /** "QT/0003" or, once altered, "QT/0003 Rev 2". */
    public function displayNumber(): string
    {
        return $this->number.($this->revision > 0 ? ' Rev '.$this->revision : '');
    }

    public function isOpen(): bool
    {
        return $this->status === self::OPEN;
    }
}
