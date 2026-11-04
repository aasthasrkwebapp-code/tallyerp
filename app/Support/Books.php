<?php

namespace App\Support;

use App\Models\Account;
use App\Models\AccountGroup;
use App\Models\Company;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Reads balances out of a company's books. Balances are signed:
 * debit positive, credit negative.
 */
class Books
{
    /** @var Collection<int, AccountGroup> */
    protected Collection $groups;

    public function __construct(public readonly Company $company)
    {
        $this->groups = $company->accountGroups()->get()->keyBy('id');
    }

    /**
     * Financial year bounds, e.g. "2026-27" → 1 Apr 2026 to 31 Mar 2027.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public function financialYear(): array
    {
        $start = CarbonImmutable::create((int) substr($this->company->financial_year, 0, 4), 4, 1);

        return [$start, $start->addYear()->subDay()];
    }

    public function groupParent(int $groupId): ?int
    {
        return $this->groups->get($groupId)?->parent_id;
    }

    /**
     * The primary (top-level) group a group sits under.
     */
    public function primaryGroup(int $groupId): AccountGroup
    {
        $group = $this->groups[$groupId];

        while ($group->parent_id && isset($this->groups[$group->parent_id])) {
            $group = $this->groups[$group->parent_id];
        }

        return $group;
    }

    /**
     * Every ledger with its closing balance on $to, including opening balance.
     *
     * Each account gets `balance` (float) and `primary` (AccountGroup) attributes.
     *
     * @return Collection<int, Account>
     */
    public function closingBalances(CarbonImmutable $to): Collection
    {
        $movements = DB::table('voucher_entries')
            ->join('vouchers', 'vouchers.id', '=', 'voucher_entries.voucher_id')
            ->where('vouchers.company_id', $this->company->id)
            ->whereDate('vouchers.date', '<=', $to->toDateString())
            ->groupBy('voucher_entries.account_id')
            ->selectRaw('voucher_entries.account_id, SUM(voucher_entries.debit) - SUM(voucher_entries.credit) AS net')
            ->pluck('net', 'account_id');

        return $this->company->accounts()
            ->with('group')
            ->orderBy('name')
            ->get()
            ->each(function (Account $account) use ($movements) {
                $account->balance = round($account->signedOpeningBalance() + (float) ($movements[$account->id] ?? 0), 2);
                $account->primary = $this->primaryGroup($account->account_group_id);
            });
    }

    /**
     * Total of all ledger opening balances plus opening stock (an asset, so a debit).
     * Non-zero means the opening entries don't balance.
     */
    public function openingDifference(): float
    {
        $ledgers = $this->company->accounts()->get()->sum(fn (Account $a) => $a->signedOpeningBalance());

        return round($ledgers + (new Stock($this->company))->openingValue(), 2);
    }

    /**
     * Statement of one ledger between two dates with a running balance.
     *
     * @return array{opening: float, rows: Collection, closing: float, debits: float, credits: float}
     */
    public function ledgerStatement(Account $account, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $before = $account->entries()
            ->whereHas('voucher', fn ($q) => $q->whereDate('date', '<', $from->toDateString()))
            ->selectRaw('COALESCE(SUM(debit), 0) - COALESCE(SUM(credit), 0) AS net')
            ->value('net');

        $opening = round($account->signedOpeningBalance() + (float) $before, 2);

        $entries = $account->entries()
            ->select('voucher_entries.*')
            ->join('vouchers', 'vouchers.id', '=', 'voucher_entries.voucher_id')
            ->whereBetween('vouchers.date', [$from->toDateString(), $to->toDateString()])
            ->orderBy('vouchers.date')
            ->orderBy('vouchers.id')
            ->with('voucher.entries.account')
            ->get();

        $running = $opening;
        $rows = $entries->map(function ($entry) use (&$running, $account) {
            $running = round($running + (float) $entry->debit - (float) $entry->credit, 2);

            // "Particulars" in Tally: the ledgers on the other side of the voucher.
            $side = $entry->debit > 0 ? 'credit' : 'debit';
            $particulars = $entry->voucher->entries
                ->where('account_id', '!=', $account->id)
                ->where($side, '>', 0)
                ->pluck('account.name')
                ->join(', ');

            return (object) [
                'voucher' => $entry->voucher,
                'particulars' => $particulars,
                'debit' => (float) $entry->debit,
                'credit' => (float) $entry->credit,
                'balance' => $running,
            ];
        });

        return [
            'opening' => $opening,
            'rows' => $rows,
            'closing' => $running,
            'debits' => round($rows->sum('debit'), 2),
            'credits' => round($rows->sum('credit'), 2),
        ];
    }
}
