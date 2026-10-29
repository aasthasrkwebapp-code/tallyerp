<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A vehicle document with an expiry date: RC, insurance, permit, fitness, PUC, road tax. */
class VehicleDocument extends Model
{
    use Auditable, BelongsToCompany;

    public const TYPES = [
        'insurance' => 'Insurance',
        'fitness' => 'Fitness certificate',
        'permit' => 'Permit',
        'national_permit' => 'National permit',
        'puc' => 'Pollution (PUC)',
        'road_tax' => 'Road tax',
        'registration' => 'Registration (RC)',
        'other' => 'Other',
    ];

    protected $fillable = ['company_id', 'vehicle_id', 'type', 'number', 'issuer', 'issued_on', 'expires_on', 'amount', 'notes'];

    protected function casts(): array
    {
        return ['issued_on' => \App\Casts\DateOnly::class, 'expires_on' => \App\Casts\DateOnly::class, 'amount' => 'decimal:2'];
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function typeLabel(): string
    {
        return self::TYPES[$this->type] ?? ucfirst($this->type);
    }

    public function auditLabel(): string
    {
        return $this->typeLabel().' of '.($this->vehicle?->number ?? 'vehicle');
    }
}
