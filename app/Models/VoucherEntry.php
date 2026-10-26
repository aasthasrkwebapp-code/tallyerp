<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VoucherEntry extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'voucher_id', 'account_id', 'line_no', 'debit', 'credit', 'cost_centre_id', 'narration'];

    protected function casts(): array
    {
        return ['debit' => 'decimal:2', 'credit' => 'decimal:2'];
    }

    public function voucher(): BelongsTo
    {
        return $this->belongsTo(Voucher::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function costCentre(): BelongsTo
    {
        return $this->belongsTo(CostCentre::class);
    }

    public function billAllocations(): HasMany
    {
        return $this->hasMany(BillAllocation::class);
    }

    public function side(): string
    {
        return Money::toPaise($this->debit) > 0 ? 'Dr' : 'Cr';
    }

    public function amountPaise(): int
    {
        return Money::toPaise($this->debit) ?: Money::toPaise($this->credit);
    }

    /** Signed amount in paise, positive for debit. */
    public function signedPaise(): int
    {
        return Money::toPaise($this->debit) - Money::toPaise($this->credit);
    }
}
