<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of an imported bank statement, optionally matched to a voucher entry.
 */
class BankStatementLine extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'account_id', 'date', 'description', 'reference', 'withdrawal', 'deposit',
        'external_id', 'voucher_entry_id', 'import_batch', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'date' => \App\Casts\DateOnly::class,
            'withdrawal' => 'decimal:2',
            'deposit' => 'decimal:2',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function entry(): BelongsTo
    {
        return $this->belongsTo(VoucherEntry::class, 'voucher_entry_id');
    }
}
