<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A driver with an advances ledger (money given to the driver and spent on trips). */
class Driver extends Model
{
    use Auditable, BelongsToCompany;

    protected $fillable = ['company_id', 'name', 'phone', 'licence_number', 'licence_expiry', 'account_id', 'is_active'];

    protected function casts(): array
    {
        return ['licence_expiry' => \App\Casts\DateOnly::class, 'is_active' => 'boolean'];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function auditLabel(): string
    {
        return 'Driver '.$this->name;
    }
}
