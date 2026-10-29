<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class StockCategory extends Model
{
    use Auditable, BelongsToCompany;

    protected $fillable = ['company_id', 'name'];
}
