<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Unit extends Model
{
    /** Units every new company starts with: [symbol, name, decimals]. */
    public const DEFAULTS = [
        ['Nos', 'Numbers', 0],
        ['Pcs', 'Pieces', 0],
        ['Kg', 'Kilograms', 3],
        ['Ltr', 'Litres', 3],
        ['Mtr', 'Metres', 2],
        ['Box', 'Boxes', 0],
    ];

    protected $fillable = [
        'company_id',
        'symbol',
        'name',
        'decimals',
    ];

    public function stockItems(): HasMany
    {
        return $this->hasMany(StockItem::class);
    }
}
