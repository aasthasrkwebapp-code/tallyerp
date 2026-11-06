<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Bom extends Model
{
    use Audited;

    protected $fillable = [
        'company_id',
        'stock_item_id',
        'name',
        'output_quantity',
    ];

    protected function casts(): array
    {
        return [
            'output_quantity' => 'decimal:3',
        ];
    }

    public function stockItem(): BelongsTo
    {
        return $this->belongsTo(StockItem::class);
    }

    public function components(): HasMany
    {
        return $this->hasMany(BomComponent::class);
    }

    public function productions(): HasMany
    {
        return $this->hasMany(Production::class);
    }

    public function auditLabel(): string
    {
        return 'BOM '.$this->name;
    }
}
