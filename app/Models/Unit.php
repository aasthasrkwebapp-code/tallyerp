<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class Unit extends Model
{
    use Auditable, BelongsToCompany;

    protected $fillable = ['company_id', 'symbol', 'name', 'decimal_places', 'uqc'];

    protected function casts(): array
    {
        return ['decimal_places' => 'integer'];
    }

    public function auditLabel(): string
    {
        return 'Unit '.$this->symbol;
    }
}
