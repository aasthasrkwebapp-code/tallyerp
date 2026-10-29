<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An earlier version of a quotation or order, saved when it is altered.
 * data holds the header fields, the lines with their amounts and the totals as they were.
 */
class OrderRevision extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'order_id', 'revision', 'data', 'grand_total', 'note', 'revised_by'];

    protected function casts(): array
    {
        return ['data' => 'array', 'grand_total' => 'decimal:2', 'revision' => 'integer'];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function reviser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revised_by');
    }
}
