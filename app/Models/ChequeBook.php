<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A book of cheque leaves for one bank account, and how to print on them.
 */
class ChequeBook extends Model
{
    use Auditable, BelongsToCompany;

    /**
     * Print positions in mm from the top-left of a CTS-2010 leaf (202 × 92 mm).
     * Each bank's leaf differs slightly, so every book can move them.
     */
    public const DEFAULT_LAYOUT = [
        'width' => 202, 'height' => 92,
        'date_x' => 155, 'date_y' => 9,
        'payee_x' => 22, 'payee_y' => 21,
        'words_x' => 30, 'words_y' => 30,
        'amount_x' => 160, 'amount_y' => 38,
        'ac_payee' => true,
    ];

    protected $fillable = ['company_id', 'account_id', 'name', 'from_number', 'to_number', 'digits', 'is_active', 'layout'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'layout' => 'array', 'from_number' => 'integer', 'to_number' => 'integer', 'digits' => 'integer'];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function spoilt(): HasMany
    {
        return $this->hasMany(SpoiltCheque::class);
    }

    public function contains(string $number): bool
    {
        return ctype_digit($number) && (int) $number >= $this->from_number && (int) $number <= $this->to_number;
    }

    public function format(int $number): string
    {
        return str_pad((string) $number, $this->digits, '0', STR_PAD_LEFT);
    }

    public function leaves(): int
    {
        return $this->to_number - $this->from_number + 1;
    }

    public function printLayout(): array
    {
        return array_merge(self::DEFAULT_LAYOUT, $this->layout ?? []);
    }
}
