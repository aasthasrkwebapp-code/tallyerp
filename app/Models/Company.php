<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Company extends Model
{
    use HasFactory, Audited;

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
        'city',
        'pincode',
        'financial_year',
        'currency',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class);
    }

    public function members(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(User::class)->withPivot('role_id')->withTimestamps();
    }

    /**
     * Companies a user owns or has been added to.
     */
    public function scopeAccessibleBy($query, User $user)
    {
        return $query->where(fn ($q) => $q
            ->where('user_id', $user->id)
            ->orWhereHas('members', fn ($m) => $m->whereKey($user->id)));
    }

    /**
     * The user's role name in this company, or null if they have no access.
     */
    public function roleFor(?User $user): ?string
    {
        if (! $user) {
            return null;
        }

        if ((int) $this->user_id === (int) $user->id) {
            return 'owner';
        }

        $roleId = $this->members()->whereKey($user->id)->value('company_user.role_id');

        return $roleId ? Role::whereKey($roleId)->value('name') : null;
    }

    public function userCan(?User $user, ?string $module, bool $writing = false): bool
    {
        $role = $this->roleFor($user);

        return $role !== null && \App\Support\Access::allows($role, $module, $writing);
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

        // Child records point at each other with restrictive foreign keys (an entry
        // protects its ledger, a stock movement its item…), so clear them in order
        // before the database cascade removes the rest.
        static::deleting(function (Company $company) {
            $vouchers = DB::table('vouchers')->where('company_id', $company->id)->pluck('id');
            $runs = DB::table('payroll_runs')->where('company_id', $company->id)->pluck('id');

            DB::table('voucher_entries')->whereIn('voucher_id', $vouchers)->delete();
            DB::table('voucher_gst_lines')->whereIn('voucher_id', $vouchers)->delete();
            DB::table('stock_movements')->where('company_id', $company->id)->delete();
            DB::table('tds_deductions')->where('company_id', $company->id)->delete();
            DB::table('payslips')->whereIn('payroll_run_id', $runs)->delete();
            DB::table('payroll_runs')->where('company_id', $company->id)->delete();
            DB::table('vouchers')->where('company_id', $company->id)->delete();
            DB::table('employees')->where('company_id', $company->id)->delete();
            DB::table('stock_items')->where('company_id', $company->id)->delete();
            DB::table('business_documents')->where('company_id', $company->id)->delete();
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

    public function tdsDeductions(): HasMany
    {
        return $this->hasMany(TdsDeduction::class);
    }

    /**
     * "TDS Payable - 194C" style ledger under Duties & Taxes, created on first use.
     */
    public function tdsLedger(string $sectionKey): Account
    {
        $group = $this->accountGroups()->where('code', 'DUTIES_TAXES')->firstOrFail();
        $section = \App\Support\Tds::SECTIONS[$sectionKey][0];

        return $this->accounts()->firstOrCreate(
            ['code' => 'TDS_'.$section],
            [
                'name' => 'TDS Payable - '.$section,
                'account_group_id' => $group->id,
                'account_type' => $group->nature,
            ],
        );
    }

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }

    public function payrollRuns(): HasMany
    {
        return $this->hasMany(PayrollRun::class);
    }

    /**
     * A ledger the app manages itself (found by code), created on first use.
     */
    public function systemLedger(string $code, string $name, string $groupCode): Account
    {
        $group = $this->accountGroups()->where('code', $groupCode)->firstOrFail();

        return $this->accounts()->firstOrCreate(
            ['code' => $code],
            ['name' => $name, 'account_group_id' => $group->id, 'account_type' => $group->nature],
        );
    }

    public function businessDocuments(): HasMany
    {
        return $this->hasMany(BusinessDocument::class);
    }

    public function currencies(): HasMany
    {
        return $this->hasMany(Currency::class);
    }

    public function budgets(): HasMany
    {
        return $this->hasMany(Budget::class);
    }

    public function costCentres(): HasMany
    {
        return $this->hasMany(CostCentre::class);
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
        // Setup data, not user activity: keep it out of the audit log.
        AccountGroup::withoutEvents(fn () => $this->seedDefaultGroups());
    }

    protected function seedDefaultGroups(): void
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
