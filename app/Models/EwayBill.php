<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Transport details and the portal's e-way bill number for a sales invoice. */
class EwayBill extends Model
{
    use Auditable, BelongsToCompany;

    /** Mode => [label, portal code]. */
    public const MODES = ['road' => ['Road', 1], 'rail' => ['Rail', 2], 'air' => ['Air', 3], 'ship' => ['Ship', 4]];

    protected $fillable = ['company_id', 'invoice_id', 'trans_mode', 'distance_km', 'vehicle_no', 'vehicle_type', 'transporter_id',
        'transporter_name', 'trans_doc_no', 'trans_doc_date', 'ewb_no', 'ewb_date', 'valid_till'];

    protected function casts(): array
    {
        return [
            'trans_doc_date' => \App\Casts\DateOnly::class, 'ewb_date' => \App\Casts\DateOnly::class,
            'valid_till' => \App\Casts\DateOnly::class, 'distance_km' => 'integer',
        ];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function isExpired(): bool
    {
        return $this->valid_till !== null && $this->valid_till->lt(now()->startOfDay());
    }

    public function auditLabel(): string
    {
        return 'E-way bill '.($this->ewb_no ?: 'details').' for '.$this->invoice?->displayNumber();
    }
}
