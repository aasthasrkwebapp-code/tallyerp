<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Account extends Model
{
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

    /**
     * Opening balance as a signed number: debit positive, credit negative.
     */
    public function signedOpeningBalance(): float
    {
        $amount = (float) $this->opening_balance;

        return $this->opening_balance_type === 'cr' ? -$amount : $amount;
    }
}
