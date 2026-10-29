<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CostCentre extends Model
{
    use Auditable, BelongsToCompany;

    protected $fillable = ['company_id', 'cost_category_id', 'parent_id', 'name', 'code', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(CostCategory::class, 'cost_category_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(CostCentre::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(CostCentre::class, 'parent_id');
    }
}
