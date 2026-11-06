<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Production extends Model
{
    use Audited;

    protected $fillable = [
        'company_id',
        'bom_id',
        'number',
        'date',
        'quantity',
        'material_cost',
        'other_cost',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'quantity' => 'decimal:3',
            'material_cost' => 'decimal:2',
            'other_cost' => 'decimal:2',
        ];
    }

    public function bom(): BelongsTo
    {
        return $this->belongsTo(Bom::class);
    }

    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    public function totalCost(): float
    {
        return round((float) $this->material_cost + (float) $this->other_cost, 2);
    }

    public function auditLabel(): string
    {
        return 'Production #'.$this->number;
    }
}
