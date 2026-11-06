<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Loan extends Model
{
    use Audited;

    protected $fillable = [
        'company_id',
        'account_id',
        'lender',
        'principal',
        'annual_rate',
        'tenure_months',
        'start_date',
        'emi',
    ];

    protected function casts(): array
    {
        return [
            'principal' => 'decimal:2',
            'annual_rate' => 'decimal:3',
            'start_date' => 'date',
            'emi' => 'decimal:2',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * Standard reducing-balance EMI: P·r·(1+r)^n / ((1+r)^n − 1), r = monthly rate.
     */
    public static function emiFor(float $principal, float $annualRate, int $months): float
    {
        $r = $annualRate / 12 / 100;
        if ($r == 0.0) {
            return round($principal / $months, 2);
        }
        $factor = (1 + $r) ** $months;

        return round($principal * $r * $factor / ($factor - 1), 2);
    }

    /**
     * Amount still owed: the credit balance of the loan ledger.
     */
    public function outstanding(): float
    {
        $account = $this->account;

        return round(-($account->signedOpeningBalance() + (float) $account->entries()->sum('debit') - (float) $account->entries()->sum('credit')), 2);
    }

    /**
     * Interest for one month on what is still owed.
     */
    public function interestOn(float $balance): float
    {
        return round($balance * (float) $this->annual_rate / 12 / 100, 2);
    }

    /**
     * Repayment schedule on the original terms.
     *
     * @return array<int, array{n: int, date: \Carbon\CarbonImmutable, emi: float, interest: float, principal: float, balance: float}>
     */
    public function schedule(): array
    {
        $balance = (float) $this->principal;
        $rows = [];

        for ($n = 1; $n <= $this->tenure_months && $balance > 0.004; $n++) {
            $interest = $this->interestOn($balance);
            // The last instalment clears whatever is left.
            $principalPart = $n === (int) $this->tenure_months ? $balance : min($balance, round((float) $this->emi - $interest, 2));
            $balance = round($balance - $principalPart, 2);

            $rows[] = [
                'n' => $n,
                'date' => \Carbon\CarbonImmutable::instance($this->start_date)->addMonthsNoOverflow($n),
                'emi' => round($principalPart + $interest, 2),
                'interest' => $interest,
                'principal' => round($principalPart, 2),
                'balance' => $balance,
            ];
        }

        return $rows;
    }

    public function auditLabel(): string
    {
        return 'Loan from '.$this->lender;
    }
}
