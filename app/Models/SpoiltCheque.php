<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A cheque leaf torn up, lost or written wrongly, so its number is never used. */
class SpoiltCheque extends Model
{
    use Auditable, BelongsToCompany;

    protected $fillable = ['company_id', 'cheque_book_id', 'number', 'date', 'reason', 'created_by'];

    protected function casts(): array
    {
        return ['date' => \App\Casts\DateOnly::class];
    }

    public function book(): BelongsTo
    {
        return $this->belongsTo(ChequeBook::class, 'cheque_book_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
