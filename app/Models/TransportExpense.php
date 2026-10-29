<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A vehicle or trip expense; each one posts a payment or journal voucher. */
class TransportExpense extends Model
{
    use Auditable, BelongsToCompany;

    protected $fillable = ['company_id', 'vehicle_id', 'trip_id', 'date', 'category', 'description', 'quantity', 'amount', 'expense_account_id', 'paid_account_id', 'voucher_id', 'status', 'created_by'];

    protected function casts(): array
    {
        return ['date' => \App\Casts\DateOnly::class, 'quantity' => 'decimal:2', 'amount' => 'decimal:2'];
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class);
    }

    public function expenseAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'expense_account_id');
    }

    public function paidAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'paid_account_id');
    }

    public function voucher(): BelongsTo
    {
        return $this->belongsTo(Voucher::class);
    }

    public function categoryLabel(): string
    {
        return config('erp.transport_expense_categories.'.$this->category.'.label', ucfirst($this->category));
    }

    public function auditLabel(): string
    {
        return 'Transport expense '.$this->categoryLabel().' '.$this->amount;
    }
}
