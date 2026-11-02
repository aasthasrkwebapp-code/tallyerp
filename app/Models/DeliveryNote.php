<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A delivery note (goods out to a customer) or goods receipt note (goods in
 * from a supplier). Posted when saved; cancelled notes no longer move stock.
 */
class DeliveryNote extends Model
{
    use Auditable, BelongsToCompany;

    protected $fillable = [
        'company_id', 'kind', 'number', 'date', 'party_account_id', 'party_name', 'reference', 'reference_date',
        'status', 'cancellation_reason', 'cancelled_at', 'cancelled_by', 'narration', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'date' => \App\Casts\DateOnly::class,
            'reference_date' => \App\Casts\DateOnly::class,
            'cancelled_at' => 'datetime',
        ];
    }

    public static function kinds(): array
    {
        return config('erp.note_kinds');
    }

    public static function kindConfig(string $kind): array
    {
        return config("erp.note_kinds.$kind") ?? throw new \InvalidArgumentException("Unknown note kind {$kind}.");
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
        return $this->hasMany(DeliveryNoteItem::class)->orderBy('line_no');
    }

    public function party(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'party_account_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isPosted(): bool
    {
        return $this->status === 'posted';
    }
}
