<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A ledger account in the chart of accounts.
 */
class Account extends Model
{
    use Auditable, BelongsToCompany;

    protected $table = 'chart_of_accounts';

    /** Ledger kinds; drives voucher rules (e.g. contra needs cash/bank). */
    public const TYPES = [
        'general' => 'General',
        'cash' => 'Cash',
        'bank' => 'Bank',
        'party' => 'Party (customer / supplier)',
        'tax' => 'Duties & taxes',
        'sales' => 'Sales',
        'purchase' => 'Purchase',
        'stock' => 'Stock',
    ];

    public const TAX_TYPES = ['cgst' => 'CGST', 'sgst' => 'SGST / UTGST', 'igst' => 'IGST', 'cess' => 'Cess', 'tds' => 'TDS', 'tcs' => 'TCS', 'other' => 'Other'];

    protected $fillable = [
        'company_id',
        'account_group_id',
        'name',
        'alias',
        'code',
        'system_key',
        'account_type',
        'opening_balance',
        'opening_balance_type',
        'is_active',
        'is_system',
        'maintain_bill_wise',
        'credit_period_days',
        'credit_limit',
        'bank_name',
        'bank_account_number',
        'ifsc',
        'bank_branch',
        'tax_type',
        'tax_rate',
        'hsn_sac',
        'gst_rate',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'opening_balance' => 'decimal:2',
            'credit_limit' => 'decimal:2',
            'tax_rate' => 'decimal:3',
            'gst_rate' => 'decimal:2',
            'is_active' => 'boolean',
            'is_system' => 'boolean',
            'maintain_bill_wise' => 'boolean',
        ];
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(AccountGroup::class, 'account_group_id');
    }

    public function partyDetails(): HasOne
    {
        return $this->hasOne(Party::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeOfType(Builder $query, string|array $types): Builder
    {
        return $query->whereIn('account_type', (array) $types);
    }

    public static function idFor(string $systemKey): ?int
    {
        return static::query()->where('system_key', $systemKey)->value('id');
    }

    /** Opening balance in paise, positive for debit. */
    public function openingPaise(): int
    {
        $paise = Money::toPaise($this->opening_balance);

        return $this->opening_balance_type === 'Cr' ? -$paise : $paise;
    }

    public function isCashOrBank(): bool
    {
        return in_array($this->account_type, ['cash', 'bank'], true);
    }

    /** True when any voucher, bill or document refers to this ledger (config erp.ledger_usage). */
    public function hasTransactions(): bool
    {
        foreach (config('erp.ledger_usage', []) as $table => $column) {
            if (\Illuminate\Support\Facades\Schema::hasTable($table) && \Illuminate\Support\Facades\DB::table($table)->where($column, $this->id)->exists()) {
                return true;
            }
        }

        return false;
    }

    public function displayName(): string
    {
        return $this->alias ? "{$this->name} ({$this->alias})" : $this->name;
    }
}
