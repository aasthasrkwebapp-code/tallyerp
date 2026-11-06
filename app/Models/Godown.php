<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Godown extends Model
{
    use Audited;

    /** Label for stock that has no godown. */
    public const MAIN = 'Main location';

    protected $fillable = ['company_id', 'name'];

    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    public function auditLabel(): string
    {
        return 'Godown '.$this->name;
    }
}
