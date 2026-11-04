<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AccountGroup extends Model
{
    public const NATURES = ['assets', 'liabilities', 'income', 'expenses'];

    /**
     * Tally's 28 predefined groups: [name, code, nature, parent code].
     */
    public const DEFAULTS = [
        ['Branch / Divisions', 'BRANCH_DIVISIONS', 'liabilities', null],
        ['Capital Account', 'CAPITAL_ACCOUNT', 'liabilities', null],
        ['Current Assets', 'CURRENT_ASSETS', 'assets', null],
        ['Current Liabilities', 'CURRENT_LIABILITIES', 'liabilities', null],
        ['Direct Expenses', 'DIRECT_EXPENSES', 'expenses', null],
        ['Direct Incomes', 'DIRECT_INCOMES', 'income', null],
        ['Fixed Assets', 'FIXED_ASSETS', 'assets', null],
        ['Indirect Expenses', 'INDIRECT_EXPENSES', 'expenses', null],
        ['Indirect Incomes', 'INDIRECT_INCOMES', 'income', null],
        ['Investments', 'INVESTMENTS', 'assets', null],
        ['Loans (Liability)', 'LOANS_LIABILITY', 'liabilities', null],
        ['Misc. Expenses (ASSET)', 'MISC_EXPENSES_ASSET', 'assets', null],
        ['Purchase Accounts', 'PURCHASE_ACCOUNTS', 'expenses', null],
        ['Sales Accounts', 'SALES_ACCOUNTS', 'income', null],
        ['Suspense A/c', 'SUSPENSE', 'liabilities', null],
        ['Reserves & Surplus', 'RESERVES_SURPLUS', 'liabilities', 'CAPITAL_ACCOUNT'],
        ['Bank Accounts', 'BANK_ACCOUNTS', 'assets', 'CURRENT_ASSETS'],
        ['Cash-in-Hand', 'CASH_IN_HAND', 'assets', 'CURRENT_ASSETS'],
        ['Deposits (Asset)', 'DEPOSITS_ASSET', 'assets', 'CURRENT_ASSETS'],
        ['Loans & Advances (Asset)', 'LOANS_ADVANCES_ASSET', 'assets', 'CURRENT_ASSETS'],
        ['Stock-in-Hand', 'STOCK_IN_HAND', 'assets', 'CURRENT_ASSETS'],
        ['Sundry Debtors', 'SUNDRY_DEBTORS', 'assets', 'CURRENT_ASSETS'],
        ['Duties & Taxes', 'DUTIES_TAXES', 'liabilities', 'CURRENT_LIABILITIES'],
        ['Provisions', 'PROVISIONS', 'liabilities', 'CURRENT_LIABILITIES'],
        ['Sundry Creditors', 'SUNDRY_CREDITORS', 'liabilities', 'CURRENT_LIABILITIES'],
        ['Bank OD A/c', 'BANK_OD', 'liabilities', 'LOANS_LIABILITY'],
        ['Secured Loans', 'SECURED_LOANS', 'liabilities', 'LOANS_LIABILITY'],
        ['Unsecured Loans', 'UNSECURED_LOANS', 'liabilities', 'LOANS_LIABILITY'],
    ];

    protected $fillable = [
        'company_id',
        'parent_id',
        'name',
        'code',
        'nature',
        'is_primary',
    ];

    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function accounts(): HasMany
    {
        return $this->hasMany(Account::class);
    }
}
