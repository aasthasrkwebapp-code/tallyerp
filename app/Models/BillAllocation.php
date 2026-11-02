<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BillAllocation extends Model
{
    use BelongsToCompany;

    public const TYPES = [
        'new_ref' => 'New Ref',
        'agst_ref' => 'Agst Ref',
        'advance' => 'Advance',
        'on_account' => 'On Account',
    ];

    protected $fillable = ['company_id', 'voucher_entry_id', 'account_id', 'bill_type', 'bill_ref', 'amount', 'side', 'due_date'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'due_date' => \App\Casts\DateOnly::class];
    }

    public function entry(): BelongsTo
    {
        return $this->belongsTo(VoucherEntry::class, 'voucher_entry_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
