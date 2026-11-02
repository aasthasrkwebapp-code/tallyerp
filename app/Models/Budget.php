<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Budget extends Model
{
    use Auditable, BelongsToCompany;

    protected $fillable = ['company_id', 'name', 'from_date', 'to_date', 'description', 'created_by'];

    protected function casts(): array
    {
        return ['from_date' => 'date', 'to_date' => 'date'];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(BudgetLine::class)->orderBy('line_no');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * The calendar months the budget covers, cut to its dates.
     *
     * @return array<int, array{0: string, 1: string, 2: string}> [from, to, "Apr 2026"]
     */
    public function periods(): array
    {
        $out = [];
        $start = $this->from_date->copy();
        while ($start->lte($this->to_date)) {
            $end = $start->copy()->endOfMonth()->min($this->to_date);
            $out[] = [$start->toDateString(), $end->toDateString(), $start->format('M Y')];
            $start = $end->copy()->addDay();
        }

        return $out;
    }

    public function days(): int
    {
        return (int) $this->from_date->diffInDays($this->to_date) + 1;
    }
}
