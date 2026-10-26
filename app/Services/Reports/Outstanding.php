<?php

namespace App\Services\Reports;

use App\Models\Account;
use App\Models\AccountGroup;
use App\Models\Voucher;
use App\Services\Accounting\BillOutstanding;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Receivables and payables with bill-wise ageing.
 *
 * A party's outstanding is its ledger balance. For bill-wise parties the
 * balance is split into its open bills (aged by due date, or bill date when
 * there is no due date) and an "on account" remainder: the opening balance
 * plus any amounts not allocated to a bill.
 */
class Outstanding
{
    public const BUCKETS = [
        'not_due' => 'Not due',
        '0_30' => '1–30 days',
        '31_60' => '31–60 days',
        '61_90' => '61–90 days',
        'over_90' => 'Over 90 days',
    ];

    public function __construct(protected BillOutstanding $bills) {}

    /** receivable => Sundry Debtors, payable => Sundry Creditors. */
    public function groupKey(string $type): string
    {
        return $type === 'payable' ? 'sundry_creditors' : 'sundry_debtors';
    }

    public function partyIds(string $type): array
    {
        $groups = AccountGroup::descendantIdsOf([AccountGroup::idFor($this->groupKey($type))]);

        return Account::query()->whereIn('account_group_id', $groups)->pluck('id')->all();
    }

    /** Signed balances (positive = debit) in paise as on a date, keyed by ledger id. */
    public function balances(array $accountIds, ?string $asOf = null): array
    {
        if ($accountIds === []) {
            return [];
        }

        $out = [];
        foreach (Account::query()->whereIn('id', $accountIds)->get(['id', 'opening_balance', 'opening_balance_type']) as $account) {
            $out[$account->id] = $account->openingPaise();
        }

        DB::table('voucher_entries as e')
            ->join('vouchers as v', 'v.id', '=', 'e.voucher_id')
            ->where('v.status', Voucher::POSTED)
            ->whereIn('e.account_id', $accountIds)
            ->when($asOf, fn ($q) => $q->where('v.date', '<=', $asOf))
            ->groupBy('e.account_id')
            ->selectRaw('e.account_id, SUM(ROUND(e.debit * 100)) as dr, SUM(ROUND(e.credit * 100)) as cr')
            ->get()
            ->each(function ($row) use (&$out) {
                $out[$row->account_id] = ($out[$row->account_id] ?? 0) + (int) $row->dr - (int) $row->cr;
            });

        return $out;
    }

    /**
     * One row per party with a balance: total and ageing buckets, in the
     * report's natural sign (receivables: debit positive; payables: credit positive).
     */
    public function summary(string $type, string $asOf, ?string $search = null): Collection
    {
        $sign = $type === 'payable' ? -1 : 1;
        $ids = $this->partyIds($type);
        $balances = $this->balances($ids, $asOf);

        $accounts = Account::query()->whereIn('id', $ids)
            ->when($search, fn ($q, $s) => $q->where('name', 'like', "%{$s}%"))
            ->orderBy('name')->get();

        return $accounts->map(function (Account $account) use ($balances, $sign, $asOf) {
            $balance = ($balances[$account->id] ?? 0) * $sign;
            if ($balance === 0) {
                return null;
            }

            $row = ['account' => $account, 'balance' => $balance, 'on_account' => $balance, 'overdue' => 0]
                + array_fill_keys(array_keys(self::BUCKETS), 0);

            if ($account->maintain_bill_wise) {
                foreach ($this->bills($account, $asOf) as $bill) {
                    $row[$bill->bucket] += $bill->amount;
                    $row['on_account'] -= $bill->amount;
                    if ($bill->bucket !== 'not_due') {
                        $row['overdue'] += $bill->amount;
                    }
                }
            }

            return (object) $row;
        })->filter()->values();
    }

    /**
     * Open bills of one party as on a date, in the report's sign.
     *
     * @return Collection<int, object{bill_ref:string, date:string, due_date:?string, original:int, amount:int, days:int, bucket:string, voucher_id:int}>
     */
    public function bills(Account $account, string $asOf): Collection
    {
        $receivable = $this->isReceivable($account);
        $date = Carbon::parse($asOf);

        return $this->bills->open($account->id, null, $asOf)->map(function ($bill) use ($receivable, $date) {
            // pending is on the bill's own side; convert to the report's sign.
            $signed = $bill->side === 'Dr' ? $bill->pending : -$bill->pending;
            $amount = $receivable ? $signed : -$signed;
            $from = Carbon::parse($bill->due_date ?: $bill->date);
            $days = (int) $from->diffInDays($date, false);

            return (object) [
                'bill_ref' => $bill->bill_ref,
                'date' => $bill->date,
                'due_date' => $bill->due_date,
                'original' => $bill->original,
                'amount' => $amount,
                'days' => max(0, $days),
                'bucket' => match (true) {
                    $days <= 0 => 'not_due',
                    $days <= 30 => '0_30',
                    $days <= 60 => '31_60',
                    $days <= 90 => '61_90',
                    default => 'over_90',
                },
                'voucher_id' => $bill->voucher_id,
            ];
        })->sortBy('date')->values();
    }

    public function isReceivable(Account $account): bool
    {
        return in_array($account->account_group_id, AccountGroup::descendantIdsOf([AccountGroup::idFor('sundry_debtors')]), true);
    }

    public static function format(int $paise): string
    {
        return $paise === 0 ? '—' : Money::format($paise, paiseInput: true);
    }
}
