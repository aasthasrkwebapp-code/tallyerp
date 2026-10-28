<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Trip extends Model
{
    use Auditable, BelongsToCompany;

    public const STATUSES = ['open' => 'On trip', 'completed' => 'Completed', 'cancelled' => 'Cancelled'];

    protected $fillable = [
        'company_id', 'number', 'vehicle_id', 'driver_id', 'transport_route_id', 'party_account_id', 'start_date', 'end_date',
        'origin', 'destination', 'lr_number', 'material', 'weight_tonnes', 'freight_amount', 'start_km', 'end_km', 'status',
        'invoice_id', 'narration', 'created_by', 'lr_date', 'consignor_name', 'consignor_address', 'consignor_gstin',
        'consignee_name', 'consignee_address', 'consignee_gstin', 'packages', 'packing', 'goods_invoice_no', 'goods_value',
        'freight_terms', 'eway_bill_no', 'eway_bill_valid_till',
    ];

    public const FREIGHT_TERMS = ['paid' => 'Paid', 'to_pay' => 'To pay', 'to_be_billed' => 'To be billed'];

    /** Goods value above which an e-way bill is generally needed (rupees). */
    public const EWAY_BILL_LIMIT = 50000;

    protected function casts(): array
    {
        return [
            'start_date' => \App\Casts\DateOnly::class,
            'end_date' => \App\Casts\DateOnly::class,
            'weight_tonnes' => 'decimal:3',
            'freight_amount' => 'decimal:2',
            'lr_date' => \App\Casts\DateOnly::class,
            'eway_bill_valid_till' => \App\Casts\DateOnly::class,
            'goods_value' => 'decimal:2',
            'packages' => 'integer',
            'start_km' => 'integer',
            'end_km' => 'integer',
        ];
    }

    /** The consignor on the LR: as entered, or else the customer billed for the freight. */
    public function consignor(): object
    {
        $details = $this->party?->partyDetails;

        return (object) [
            'name' => $this->consignor_name ?: $this->party?->name,
            'address' => $this->consignor_name ? $this->consignor_address : $details?->fullAddress(),
            'gstin' => $this->consignor_name ? $this->consignor_gstin : $details?->gstin,
        ];
    }

    public function ewayBillExpired(?string $asOf = null): bool
    {
        return $this->eway_bill_valid_till && $this->eway_bill_valid_till->toDateString() < ($asOf ?? now()->toDateString());
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }

    public function route(): BelongsTo
    {
        return $this->belongsTo(TransportRoute::class, 'transport_route_id');
    }

    public function party(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'party_account_id');
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(TransportExpense::class);
    }

    /** The freight invoice, when it is posted (a cancelled or draft bill does not count). */
    public function postedInvoice(): ?Invoice
    {
        return $this->invoice && $this->invoice->status() === Voucher::POSTED ? $this->invoice : null;
    }

    public function distance(): ?int
    {
        return $this->start_km !== null && $this->end_km !== null ? $this->end_km - $this->start_km : null;
    }

    public function auditLabel(): string
    {
        return 'Trip '.$this->number;
    }
}
