<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Contact and GST details of a customer or supplier ledger.
 */
class Party extends Model
{
    use Auditable, BelongsToCompany;

    protected $fillable = [
        'company_id', 'account_id', 'gst_registration_type', 'gstin', 'pan', 'contact_person', 'phone',
        'email', 'billing_address', 'city', 'state', 'pincode', 'country', 'shipping_address',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /** Billing address as printed on invoices. */
    public function fullAddress(): string
    {
        $tail = trim(implode(' ', array_filter([$this->city, $this->pincode ? '- '.$this->pincode : null])));

        return trim(implode("\n", array_filter([$this->billing_address, $tail, $this->state])));
    }

    public function auditLabel(): string
    {
        return 'Party details of '.($this->account?->name ?? '#'.$this->account_id);
    }
}
