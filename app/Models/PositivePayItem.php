<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A cheque reported (issue) or withdrawn (cancel) in a positive pay file, as it was sent. */
class PositivePayItem extends Model
{
    use BelongsToCompany;

    public const ACTIONS = ['issue' => 'Issued', 'cancel' => 'Cancelled'];

    protected $fillable = ['company_id', 'positive_pay_batch_id', 'voucher_id', 'action', 'cheque_number', 'cheque_date', 'amount', 'payee_name'];

    protected function casts(): array
    {
        return ['cheque_date' => \App\Casts\DateOnly::class, 'amount' => 'decimal:2'];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(PositivePayBatch::class, 'positive_pay_batch_id');
    }

    public function voucher(): BelongsTo
    {
        return $this->belongsTo(Voucher::class);
    }
}
