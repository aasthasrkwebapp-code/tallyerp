<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CostCentre extends Model
{
    protected $fillable = [
        'company_id',
        'parent_id',
        'name',
    ];

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function entries(): HasMany
    {
        return $this->hasMany(VoucherEntry::class);
    }
}
