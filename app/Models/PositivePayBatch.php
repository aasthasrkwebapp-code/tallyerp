<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One positive pay file sent to a bank. */
class PositivePayBatch extends Model
{
    use Auditable, BelongsToCompany;

    protected $fillable = ['company_id', 'account_id', 'threshold', 'item_count', 'total', 'created_by'];

    protected function casts(): array
    {
        return ['threshold' => 'decimal:2', 'total' => 'decimal:2'];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(PositivePayItem::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function auditLabel(): string
    {
        return 'Positive pay file #'.$this->id;
    }
}
