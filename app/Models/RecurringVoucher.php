<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A voucher template with a schedule (rent, EMIs, monthly provisions).
 * Each due date makes an ordinary voucher; see RecurringVoucherService.
 */
class RecurringVoucher extends Model
{
    use Auditable, BelongsToCompany;

    public const FREQUENCIES = [
        'weekly' => 'Weekly',
        'monthly' => 'Monthly',
        'quarterly' => 'Quarterly',
        'half_yearly' => 'Half-yearly',
        'yearly' => 'Yearly',
    ];

    public const MODES = ['post' => 'Post (or send for approval)', 'draft' => 'Save as draft for review'];

    protected $fillable = [
        'company_id', 'name', 'voucher_type_id', 'frequency', 'start_date', 'next_date', 'end_date', 'mode',
        'reference', 'narration', 'total', 'is_active', 'last_run_at', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => \App\Casts\DateOnly::class,
            'next_date' => \App\Casts\DateOnly::class,
            'end_date' => \App\Casts\DateOnly::class,
            'total' => 'decimal:2',
            'is_active' => 'boolean',
            'last_run_at' => 'datetime',
        ];
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(VoucherType::class, 'voucher_type_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(RecurringVoucherLine::class)->orderBy('line_no');
    }

    public function runs(): HasMany
    {
        return $this->hasMany(RecurringVoucherRun::class)->orderByDesc('date')->orderByDesc('id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * The occurrence after $date. Monthly and longer schedules keep the start
     * date's day of month, falling back to the month's last day (31 Jan → 28 Feb → 31 Mar).
     */
    public function dateAfter(Carbon|string $date): Carbon
    {
        $date = Carbon::parse($date);
        if ($this->frequency === 'weekly') {
            return $date->copy()->addWeek();
        }
        $months = ['monthly' => 1, 'quarterly' => 3, 'half_yearly' => 6, 'yearly' => 12][$this->frequency];
        $day = Carbon::parse($this->start_date)->day;
        $next = $date->copy()->startOfMonth()->addMonthsNoOverflow($months);

        return $next->day(min($day, $next->daysInMonth));
    }

    /** True when the schedule has a date due on or before $asOf. */
    public function isDue(string $asOf): bool
    {
        return $this->is_active && $this->next_date !== null && $this->next_date->toDateString() <= $asOf;
    }

    public function frequencyLabel(): string
    {
        return self::FREQUENCIES[$this->frequency] ?? $this->frequency;
    }
}
