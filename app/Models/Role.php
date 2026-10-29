<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Role extends Model
{
    use Auditable;

    protected $fillable = [
        'company_id',
        'name',
        'description',
        'is_system',
    ];

    protected function casts(): array
    {
        return ['is_system' => 'boolean'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class);
    }

    /** System roles plus the roles defined by the given company. */
    public function scopeAvailableTo(Builder $query, Company $company): Builder
    {
        return $query->where(fn ($q) => $q->whereNull('company_id')->orWhere('company_id', $company->id));
    }

    public function isSuperRole(): bool
    {
        return $this->is_system && in_array($this->name, config('erp.super_roles'), true);
    }

    public function permissionKeys(): array
    {
        return $this->permissions->pluck('key')->all();
    }
}
