<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockJournalLine extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'stock_journal_id', 'stock_item_id', 'godown_id', 'direction', 'quantity', 'rate', 'value'];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:3', 'rate' => 'decimal:2', 'value' => 'decimal:2'];
    }

    public function journal(): BelongsTo
    {
        return $this->belongsTo(StockJournal::class, 'stock_journal_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(StockItem::class, 'stock_item_id');
    }

    public function godown(): BelongsTo
    {
        return $this->belongsTo(Godown::class);
    }
}
