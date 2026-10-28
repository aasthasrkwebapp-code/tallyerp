<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One component of a stock item's bill of materials. */
class BomComponent extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'stock_item_id', 'component_id', 'quantity', 'line_no'];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:3'];
    }

    public function component(): BelongsTo
    {
        return $this->belongsTo(StockItem::class, 'component_id');
    }
}
