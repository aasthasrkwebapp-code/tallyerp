<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One line of a stock item's bill of materials: a component used, or a by-product or scrap that comes out. */
class BomComponent extends Model
{
    use BelongsToCompany;

    public const KINDS = ['component' => 'Component', 'by_product' => 'By-product', 'scrap' => 'Scrap'];

    protected $attributes = ['kind' => 'component'];

    protected $fillable = ['company_id', 'stock_item_id', 'component_id', 'kind', 'quantity', 'rate', 'line_no'];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:3', 'rate' => 'decimal:2'];
    }

    public function isOutput(): bool
    {
        return $this->kind !== 'component';
    }

    public function component(): BelongsTo
    {
        return $this->belongsTo(StockItem::class, 'component_id');
    }
}
