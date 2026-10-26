<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Voucher extends Model
{
    use Auditable, BelongsToCompany;

    public const DRAFT = 'draft';
    public const PENDING = 'pending_approval';
    public const POSTED = 'posted';
    public const CANCELLED = 'cancelled';

    public const STATUSES = [
        self::DRAFT => 'Draft',
        self::PENDING => 'Pending approval',
        self::POSTED => 'Posted',
        self::CANCELLED => 'Cancelled',
    ];

    protected $fillable = [
        'company_id', 'voucher_type_id', 'financial_year_id', 'voucher_number', 'sequence_number', 'date',
        'reference', 'reference_date', 'narration', 'status', 'total', 'party_account_id', 'reversal_of_id',
        'source_type', 'source_id', 'revision', 'created_by', 'updated_by', 'approved_by', 'approved_at',
        'posted_at', 'cancelled_by', 'cancelled_at', 'cancellation_reason',
    ];

    protected function casts(): array
    {
        return [
            'date' => \App\Casts\DateOnly::class,
            'reference_date' => \App\Casts\DateOnly::class,
            'total' => 'decimal:2',
            'approved_at' => 'datetime',
            'posted_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(VoucherType::class, 'voucher_type_id');
    }

    public function financialYear(): BelongsTo
    {
        return $this->belongsTo(FinancialYear::class);
    }

    public function entries(): HasMany
    {
        return $this->hasMany(VoucherEntry::class)->orderBy('line_no');
    }

    public function party(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'party_account_id');
    }

    public function reversalOf(): BelongsTo
    {
        return $this->belongsTo(Voucher::class, 'reversal_of_id');
    }

    public function reversal(): HasOne
    {
        return $this->hasOne(Voucher::class, 'reversal_of_id');
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(VoucherRevision::class)->orderByDesc('revision');
    }

    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function scopePosted(Builder $query): Builder
    {
        return $query->where('status', self::POSTED);
    }

    public function isDraft(): bool
    {
        return $this->status === self::DRAFT;
    }

    public function isPending(): bool
    {
        return $this->status === self::PENDING;
    }

    public function isPosted(): bool
    {
        return $this->status === self::POSTED;
    }

    public function isCancelled(): bool
    {
        return $this->status === self::CANCELLED;
    }

    public function displayNumber(): string
    {
        return $this->voucher_number ?? ('Draft #'.$this->id);
    }

    public function auditLabel(): string
    {
        return ($this->type?->name ?? 'Voucher').' '.$this->displayNumber();
    }
}
