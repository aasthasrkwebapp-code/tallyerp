<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Company extends Model
{
    use Auditable, HasFactory;

    /** Preference defaults; stored overrides live in the `settings` JSON column. */
    public const DEFAULT_SETTINGS = [
        'maintain_bill_wise' => true,
        'enable_cost_centres' => false,
        'enable_inventory' => true,
        'enable_gst' => true,
        'allow_future_dated_vouchers' => false,
        'require_voucher_approval' => false,
        'invoice_terms' => null,
        'invoice_footer' => null,
        'invoice_show_bank_details' => true,
        'invoice_round_off' => true,
        'backup_frequency' => 'off', // off, daily, weekly
        'backup_keep' => 7,
    ];

    protected $fillable = [
        'user_id',
        'name',
        'legal_name',
        'mailing_name',
        'gstin',
        'pan',
        'cin',
        'tan',
        'email',
        'phone',
        'website',
        'address',
        'city',
        'state',
        'pincode',
        'country',
        'financial_year',
        'books_begin_date',
        'currency',
        'logo_path',
        'settings',
    ];

    protected function casts(): array
    {
        return [
            'books_begin_date' => \App\Casts\DateOnly::class,
            'settings' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class)
            ->withPivot(['role_id', 'is_active'])
            ->withTimestamps();
    }

    public function roles(): HasMany
    {
        return $this->hasMany(Role::class);
    }

    public function financialYears(): HasMany
    {
        return $this->hasMany(FinancialYear::class)->orderByDesc('start_date');
    }

    public function accountingPeriods(): HasMany
    {
        return $this->hasMany(AccountingPeriod::class);
    }

    public function accountGroups(): HasMany
    {
        return $this->hasMany(AccountGroup::class);
    }

    public function accounts(): HasMany
    {
        return $this->hasMany(Account::class);
    }

    public function currentFinancialYear(): ?FinancialYear
    {
        return FinancialYear::withoutGlobalScopes()
            ->where('company_id', $this->id)
            ->orderByDesc('is_current')
            ->orderByDesc('start_date')
            ->first();
    }

    /** Financial year containing the given date, if any. */
    public function financialYearFor(\DateTimeInterface|string $date): ?FinancialYear
    {
        $date = \Illuminate\Support\Carbon::parse($date)->toDateString();

        return FinancialYear::withoutGlobalScopes()
            ->where('company_id', $this->id)
            ->whereDate('start_date', '<=', $date)
            ->whereDate('end_date', '>=', $date)
            ->first();
    }

    public function setting(string $key, mixed $default = null): mixed
    {
        return ($this->settings ?? [])[$key] ?? self::DEFAULT_SETTINGS[$key] ?? $default;
    }

    public function allSettings(): array
    {
        return array_merge(self::DEFAULT_SETTINGS, $this->settings ?? []);
    }

    public function displayName(): string
    {
        return $this->mailing_name ?: $this->name;
    }
}
