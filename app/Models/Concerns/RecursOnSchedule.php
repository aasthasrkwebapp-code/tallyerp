<?php

namespace App\Models\Concerns;

use Illuminate\Support\Carbon;

/**
 * A template that repeats weekly to yearly from start_date, with next_date the
 * next occurrence (null once ended) and an optional end_date.
 */
trait RecursOnSchedule
{
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
        return \App\Models\RecurringVoucher::FREQUENCIES[$this->frequency] ?? $this->frequency;
    }
}
