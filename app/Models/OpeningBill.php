<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One bill of a bill-wise party's opening balance. */
class OpeningBill extends Model
{
    use Auditable, BelongsToCompany;

    protected $fillable = ['company_id', 'account_id', 'bill_ref', 'date', 'due_date', 'amount', 'side'];

    protected function casts(): array
    {
        return ['date' => \App\Casts\DateOnly::class, 'due_date' => \App\Casts\DateOnly::class, 'amount' => 'decimal:2'];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function auditLabel(): string
    {
        return "Opening bill {$this->bill_ref}";
    }
}
