<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/** The acknowledgement (token) number of a quarterly salary TDS return (Form 24Q). */
class TdsReturn extends Model
{
    use Auditable, BelongsToCompany;

    protected $fillable = ['company_id', 'financial_year_id', 'quarter', 'receipt_number', 'filed_on'];

    protected function casts(): array
    {
        return ['filed_on' => \App\Casts\DateOnly::class, 'quarter' => 'integer'];
    }

    public function auditLabel(): string
    {
        return '24Q return Q'.$this->quarter;
    }
}
