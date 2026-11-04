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
        'is_active',
        'gstin',
        'state_code',
        'hsn_sac',
        'gst_rate',
        'pan',
        'tds_section',
    ];

    protected function casts(): array
    {
        return [
            'opening_balance' => 'decimal:2',
            'is_active' => 'boolean',
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
