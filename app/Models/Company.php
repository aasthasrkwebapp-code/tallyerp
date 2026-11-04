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

    protected static function booted(): void
    {
        static::created(fn (Company $company) => $company->createDefaultGroups());
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
