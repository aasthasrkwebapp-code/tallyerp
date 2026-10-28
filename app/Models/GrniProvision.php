<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A provision for goods received but not invoiced on a date: a journal and, usually, its reversal the next day. */
class GrniProvision extends Model
{
    use Auditable, BelongsToCompany;

    protected $fillable = ['company_id', 'date', 'amount', 'note_lines', 'status', 'voucher_id', 'reversal_voucher_id', 'cancellation_reason', 'created_by'];

    protected function casts(): array
    {
        return ['date' => \App\Casts\DateOnly::class, 'amount' => 'decimal:2', 'note_lines' => 'integer'];
    }

    public function voucher(): BelongsTo
    {
        return $this->belongsTo(Voucher::class);
    }

    public function reversal(): BelongsTo
    {
        return $this->belongsTo(Voucher::class, 'reversal_voucher_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function auditLabel(): string
    {
        return 'GRNI provision '.$this->date->format('d M Y');
    }
}
