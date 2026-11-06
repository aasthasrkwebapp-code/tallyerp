<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Account extends Model
{
    use Audited;

    protected $table = 'chart_of_accounts';

    protected $fillable = [
        'company_id',
        'account_group_id',
        'name',
        'code',
        'account_type',
        'opening_balance',
        'opening_balance_type',
        'currency_code',
        'opening_foreign_amount',
        'is_active',
        'gstin',
        'state_code',
        'address',
        'city',
        'pincode',
        'phone',
        'email',
        'hsn_sac',
        'gst_rate',
        'itc_blocked',
        'itc_common',
        'pan',
        'tds_section',
        'price_list_id',
        'credit_days',
        'credit_limit',
        'depreciation_rate',
    ];

    protected function casts(): array
    {
        return [
            'opening_balance' => 'decimal:2',
            'credit_limit' => 'decimal:2',
            'depreciation_rate' => 'decimal:2',
            'credit_days' => 'integer',
            'opening_foreign_amount' => 'decimal:2',
            'is_active' => 'boolean',
            'itc_blocked' => 'boolean',
            'itc_common' => 'boolean',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(AccountGroup::class, 'account_group_id');
    }

    public function entries(): HasMany
    {
        return $this->hasMany(VoucherEntry::class);
    }

    /**
     * Foreign-currency opening balance, signed like the rupee one (debit positive).
     */
    public function signedOpeningForeign(): float
    {
        $amount = (float) $this->opening_foreign_amount;

        return $this->opening_balance_type === 'cr' ? -$amount : $amount;
    }

    /**
     * Opening balance as a signed number: debit positive, credit negative.
     */
    public function signedOpeningBalance(): float
    {
        $amount = (float) $this->opening_balance;

        return $this->opening_balance_type === 'cr' ? -$amount : $amount;
    }

    public function auditLabel(): string
    {
        return 'Ledger '.$this->name;
    }
}
