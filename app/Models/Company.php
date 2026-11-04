<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Company extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'name',
        'legal_name',
        'gstin',
        'pan',
        'email',
        'phone',
        'address',
        'state',
        'financial_year',
        'currency',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function accountGroups(): HasMany
    {
        return $this->hasMany(AccountGroup::class);
    }

    public function accounts(): HasMany
    {
        return $this->hasMany(Account::class);
    }

    public function vouchers(): HasMany
    {
        return $this->hasMany(Voucher::class);
    }

    public function stateCode(): ?string
    {
        return \App\Support\GstStates::codeFor($this->gstin, $this->state);
    }

    /**
     * The ledger for one GST head, e.g. ("output", "cgst") → "Output CGST"
     * under Duties & Taxes. Created on first use.
     */
    public function gstLedger(string $direction, string $head): Account
    {
        $group = $this->accountGroups()->where('code', 'DUTIES_TAXES')->firstOrFail();

        return $this->accounts()->firstOrCreate(
            ['code' => strtoupper("GST_{$direction}_{$head}")],
            [
                'name' => ucfirst($direction).' '.strtoupper($head),
                'account_group_id' => $group->id,
                'account_type' => $group->nature,
            ],
        );
    }

    protected static function booted(): void
    {
        static::saving(function (Company $company) {
            $company->books_start_year ??= (int) substr($company->financial_year, 0, 4);
        });

        static::created(function (Company $company) {
            $company->createDefaultGroups();
            $company->createDefaultUnits();
        });
    }

    public function units(): HasMany
    {
        return $this->hasMany(Unit::class);
    }

    public function stockGroups(): HasMany
    {
        return $this->hasMany(StockGroup::class);
    }

    public function stockItems(): HasMany
    {
        return $this->hasMany(StockItem::class);
    }

    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    public function createDefaultUnits(): void
    {
        foreach (Unit::DEFAULTS as [$symbol, $name, $decimals]) {
            $this->units()->firstOrCreate(['symbol' => $symbol], ['name' => $name, 'decimals' => $decimals]);
        }
    }

    /**
     * Create Tally's predefined account groups for this company.
     */
    public function createDefaultGroups(): void
    {
        $ids = [];

        foreach (AccountGroup::DEFAULTS as [$name, $code, $nature, $parent]) {
            $ids[$code] = $this->accountGroups()->firstOrCreate(
                ['code' => $code],
                [
                    'name' => $name,
                    'nature' => $nature,
                    'parent_id' => $parent ? $ids[$parent] : null,
                    'is_primary' => $parent === null,
                ],
            )->id;
        }
    }
}
