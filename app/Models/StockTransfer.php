<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StockTransfer extends Model
{
    use Audited;

    protected $fillable = ['company_id', 'number', 'date', 'notes'];

    protected function casts(): array
    {
        return ['date' => 'date'];
    }

    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    public function auditLabel(): string
    {
        return 'Stock transfer #'.$this->number;
    }
}
