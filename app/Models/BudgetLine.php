<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BudgetLine extends Model
{
    use BelongsToCompany;

    public const BASES = [
        'nett' => 'Transactions in the period',
        'closing' => 'Closing balance',
    ];

    public $timestamps = false;

    protected $fillable = ['company_id', 'budget_id', 'line_no', 'account_group_id', 'account_id', 'cost_centre_id', 'basis', 'amount'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2'];
    }

    public function budget(): BelongsTo
    {
        return $this->belongsTo(Budget::class);
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(AccountGroup::class, 'account_group_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function costCentre(): BelongsTo
    {
        return $this->belongsTo(CostCentre::class);
    }

    /** "g:12" for a group line, "l:34" for a ledger line, as used by the form. */
    public function target(): string
    {
        return $this->account_id ? 'l:'.$this->account_id : 'g:'.$this->account_group_id;
    }

    /** Nature of the group the line belongs to: asset, liability, income or expense. */
    public function nature(): string
    {
        return $this->account_id ? $this->account->group->nature : $this->group->nature;
    }
}
