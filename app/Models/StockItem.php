<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StockItem extends Model
{
    use Audited;

    protected $fillable = [
        'company_id',
        'stock_group_id',
        'unit_id',
        'name',
        'hsn_sac',
        'gst_rate',
        'opening_quantity',
        'opening_rate',
        'barcode',
        'selling_price',
        'track_batches',
    ];

    protected function casts(): array
    {
        return [
            'gst_rate' => 'decimal:2',
            'opening_quantity' => 'decimal:3',
            'opening_rate' => 'decimal:2',
            'selling_price' => 'decimal:2',
            'track_batches' => 'boolean',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(StockGroup::class, 'stock_group_id');
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function batches(): HasMany
    {
        return $this->hasMany(StockBatch::class);
    }

    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    public function openingValue(): float
    {
        return round((float) $this->opening_quantity * (float) $this->opening_rate, 2);
    }

    public function auditLabel(): string
    {
        return 'Stock item '.$this->name;
    }
}
