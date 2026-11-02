<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CostCategory extends Model
{
    use Auditable, BelongsToCompany;

    protected $fillable = ['company_id', 'name', 'allocate_revenue', 'allocate_non_revenue'];

    protected function casts(): array
    {
        return ['allocate_revenue' => 'boolean', 'allocate_non_revenue' => 'boolean'];
    }

    public function costCentres(): HasMany
    {
        return $this->hasMany(CostCentre::class);
    }
}
