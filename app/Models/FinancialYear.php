<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class FinancialYear extends Model
{
    use Auditable, BelongsToCompany;

    protected $fillable = [
        'company_id', 'name', 'start_date', 'end_date', 'is_current',
        'is_locked', 'locked_at', 'locked_by',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'is_current' => 'boolean',
            'is_locked' => 'boolean',
            'locked_at' => 'datetime',
        ];
    }

    public function periods(): HasMany
    {
        return $this->hasMany(AccountingPeriod::class)->orderBy('start_date');
    }

    /** "2026-27" style label for a year starting on the given date. */
    public static function labelFor(Carbon $start): string
    {
        $end = $start->copy()->addYear()->subDay();

        return $start->year === $end->year
            ? (string) $start->year
            : $start->year.'-'.substr((string) $end->year, -2);
    }

    /**
     * Create a twelve-month financial year with monthly accounting periods.
     */
    public static function open(Company $company, Carbon|string $start, bool $makeCurrent = true): self
    {
        $start = Carbon::parse($start)->startOfDay();
        $end = $start->copy()->addYear()->subDay();

        return DB::transaction(function () use ($company, $start, $end, $makeCurrent) {
            if ($makeCurrent) {
                static::withoutGlobalScopes()->where('company_id', $company->id)->update(['is_current' => false]);
            }

            $year = static::create([
                'company_id' => $company->id,
                'name' => static::labelFor($start),
                'start_date' => $start->toDateString(),
                'end_date' => $end->toDateString(),
                'is_current' => $makeCurrent,
            ]);

            $cursor = $start->copy();
            while ($cursor->lte($end)) {
                $periodEnd = $cursor->copy()->endOfMonth()->startOfDay();
                if ($periodEnd->gt($end)) {
                    $periodEnd = $end->copy();
                }

                $year->periods()->create([
                    'company_id' => $company->id,
                    'name' => $cursor->format('M Y'),
                    'start_date' => $cursor->toDateString(),
                    'end_date' => $periodEnd->toDateString(),
                ]);

                $cursor = $periodEnd->copy()->addDay();
            }

            return $year;
        });
    }

    public function contains(\DateTimeInterface|string $date): bool
    {
        $date = Carbon::parse($date)->startOfDay();

        return $date->betweenIncluded($this->start_date, $this->end_date);
    }

    /** Whether postings dated on $date are blocked by a year or period lock. */
    public function isDateLocked(\DateTimeInterface|string $date): bool
    {
        if ($this->is_locked) {
            return true;
        }

        $date = Carbon::parse($date)->toDateString();

        return $this->periods()
            ->whereDate('start_date', '<=', $date)
            ->whereDate('end_date', '>=', $date)
            ->where('is_locked', true)
            ->exists();
    }
}
