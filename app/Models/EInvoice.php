<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A sales invoice or credit note registered on the e-invoice portal. */
class EInvoice extends Model
{
    use Auditable, BelongsToCompany;

    protected $table = 'e_invoices';

    protected $fillable = ['company_id', 'invoice_id', 'irn', 'ack_no', 'ack_date', 'signed_qr', 'cancelled_at'];

    protected array $auditExclude = ['signed_qr'];

    protected function casts(): array
    {
        return ['ack_date' => 'datetime', 'cancelled_at' => 'datetime'];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function auditLabel(): string
    {
        return 'E-invoice IRN for '.$this->invoice?->displayNumber();
    }
}
