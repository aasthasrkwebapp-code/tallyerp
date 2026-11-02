<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/** A tyre tracked by serial number: in stock, fitted to a vehicle, or scrapped. */
class Tyre extends Model
{
    use Auditable, BelongsToCompany;

    public const STATUSES = ['stock' => 'In stock', 'fitted' => 'Fitted', 'scrapped' => 'Scrapped'];

    /** Usual wheel positions; any short code is accepted. */
    public const POSITIONS = ['FL' => 'Front left', 'FR' => 'Front right', 'RLO' => 'Rear left outer', 'RLI' => 'Rear left inner',
        'RRO' => 'Rear right outer', 'RRI' => 'Rear right inner', 'R2LO' => '2nd rear left outer', 'R2LI' => '2nd rear left inner',
        'R2RO' => '2nd rear right outer', 'R2RI' => '2nd rear right inner', 'SPARE' => 'Spare'];

    protected $attributes = ['status' => 'stock', 'retreads' => 0, 'retread_cost' => '0.00', 'cost' => '0.00'];

    protected $fillable = ['company_id', 'serial_no', 'brand', 'size', 'purchased_on', 'cost', 'supplier', 'expected_km',
        'retreads', 'retread_cost', 'status', 'scrapped_on', 'scrap_reason', 'notes'];

    protected function casts(): array
    {
        return ['purchased_on' => \App\Casts\DateOnly::class, 'scrapped_on' => \App\Casts\DateOnly::class, 'cost' => 'decimal:2',
            'retread_cost' => 'decimal:2', 'expected_km' => 'integer', 'retreads' => 'integer'];
    }

    public function fitments(): HasMany
    {
        return $this->hasMany(TyreFitment::class);
    }

    public function current(): HasOne
    {
        return $this->hasOne(TyreFitment::class)->whereNull('removed_on')->latestOfMany();
    }

    public function auditLabel(): string
    {
        return 'Tyre '.$this->serial_no;
    }
}
