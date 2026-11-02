<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Vehicle extends Model
{
    use Auditable, BelongsToCompany;

    protected $fillable = ['company_id', 'number', 'type', 'make_model', 'capacity_tonnes', 'ownership', 'is_active', 'notes'];

    protected function casts(): array
    {
        return ['capacity_tonnes' => 'decimal:2', 'is_active' => 'boolean'];
    }

    public function trips(): HasMany
    {
        return $this->hasMany(Trip::class);
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(TransportExpense::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(VehicleDocument::class);
    }

    public function auditLabel(): string
    {
        return 'Vehicle '.$this->number;
    }
}
