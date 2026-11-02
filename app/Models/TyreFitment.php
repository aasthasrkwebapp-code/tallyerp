<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One spell of a tyre on a vehicle, from fitting to removal. */
class TyreFitment extends Model
{
    use Auditable, BelongsToCompany;

    public const REASONS = ['rotation' => 'Rotation / moved', 'puncture' => 'Puncture or repair', 'retread' => 'Sent for retreading',
        'worn' => 'Worn out', 'damage' => 'Damaged', 'vehicle' => 'Vehicle sold or off the road', 'other' => 'Other'];

    protected $fillable = ['company_id', 'tyre_id', 'vehicle_id', 'position', 'fitted_on', 'fitted_km', 'removed_on', 'removed_km', 'removal_reason', 'notes'];

    protected function casts(): array
    {
        return ['fitted_on' => \App\Casts\DateOnly::class, 'removed_on' => \App\Casts\DateOnly::class, 'fitted_km' => 'integer', 'removed_km' => 'integer'];
    }

    public function tyre(): BelongsTo
    {
        return $this->belongsTo(Tyre::class);
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function auditLabel(): string
    {
        return 'Tyre '.($this->tyre?->serial_no ?? '').' on '.($this->vehicle?->number ?? 'vehicle').' '.$this->position;
    }
}
