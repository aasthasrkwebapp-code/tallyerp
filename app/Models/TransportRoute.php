<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class TransportRoute extends Model
{
    use Auditable, BelongsToCompany;

    protected $fillable = ['company_id', 'name', 'origin', 'destination', 'distance_km'];

    protected function casts(): array
    {
        return ['distance_km' => 'decimal:1'];
    }

    public function auditLabel(): string
    {
        return 'Route '.$this->name;
    }
}
