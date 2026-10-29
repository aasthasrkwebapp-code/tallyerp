<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One line of a stock item's bill of materials: a component used, or a co-product, by-product or scrap that comes out. */
class BomComponent extends Model
{
    use BelongsToCompany;

    public const KINDS = ['component' => 'Component', 'co_product' => 'Co-product', 'by_product' => 'By-product', 'scrap' => 'Scrap'];

    protected $attributes = ['kind' => 'component'];

    protected $fillable = ['company_id', 'stock_item_id', 'component_id', 'kind', 'quantity', 'rate', 'cost_share', 'line_no'];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:3', 'rate' => 'decimal:2', 'cost_share' => 'decimal:2'];
    }

    public function isOutput(): bool
    {
        return $this->kind !== 'component';
    }

    /** A co-product shares the cost; by-products and scrap are valued at a rate and reduce it. */
    public function isCoProduct(): bool
    {
        return $this->kind === 'co_product';
    }

    public function component(): BelongsTo
    {
        return $this->belongsTo(StockItem::class, 'component_id');
    }
}
