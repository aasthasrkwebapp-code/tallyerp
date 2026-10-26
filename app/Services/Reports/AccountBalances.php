<?php

namespace App\Services\Reports;

use App\Models\Account;
use App\Models\AccountGroup;
use App\Models\Voucher;
use App\Support\CurrentCompany;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Ledger balances computed directly from posted voucher entries.
 *
 * All amounts are integer paise; positive = debit, negative = credit.
 * Income and expense ledgers start every financial year at zero: their
 * earlier totals are carried in the accumulated "Profit & Loss A/c".
 */
class AccountBalances
{
    public function __construct(protected CurrentCompany $current) {}

    /**
     * @return Collection<int, object{account: Account, opening:int, debit:int, credit:int, closing:int}>
     */
    public function forPeriod(string $from, string $to): Collection
    {
        $from = Carbon::parse($from)->toDateString();
        $to = Carbon::parse($to)->toDateString();
        $yearStart = $this->yearStart($from);

        $movements = DB::table('voucher_entries as e')
            ->join('vouchers as v', 'v.id', '=', 'e.voucher_id')
            ->where('v.company_id', $this->current->id())
            ->where('v.status', Voucher::POSTED)
            ->where('v.date', '<=', $to)
            ->groupBy('e.account_id')
            ->selectRaw('e.account_id')
            ->selectRaw('SUM(CASE WHEN v.date < ? THEN ROUND(e.debit * 100) - ROUND(e.credit * 100) ELSE 0 END) as before_from', [$from])
            ->selectRaw('SUM(CASE WHEN v.date < ? THEN ROUND(e.debit * 100) - ROUND(e.credit * 100) ELSE 0 END) as before_year', [$yearStart])
            ->selectRaw('SUM(CASE WHEN v.date >= ? THEN ROUND(e.debit * 100) ELSE 0 END) as period_debit', [$from])
            ->selectRaw('SUM(CASE WHEN v.date >= ? THEN ROUND(e.credit * 100) ELSE 0 END) as period_credit', [$from])
            ->get()
            ->keyBy('account_id');

        return Account::with('group')->orderBy('name')->get()->map(function (Account $account) use ($movements) {
            $m = $movements->get($account->id);
            $beforeFrom = (int) round((float) ($m->before_from ?? 0));
            $beforeYear = (int) round((float) ($m->before_year ?? 0));
            $isPnl = in_array($account->group->nature, ['income', 'expense'], true);

            $opening = $isPnl ? $beforeFrom - $beforeYear : $account->openingPaise() + $beforeFrom;
            $debit = (int) round((float) ($m->period_debit ?? 0));
            $credit = (int) round((float) ($m->period_credit ?? 0));

            return (object) [
                'account' => $account,
                'opening' => $opening,
                'debit' => $debit,
                'credit' => $credit,
                'closing' => $opening + $debit - $credit,
                'pnl_before_year' => $isPnl ? $beforeYear : 0,
            ];
        });
    }

    /**
     * Net of all ledger opening balances plus the opening stock value
     * (opening stock is a debit, like an asset). Should be zero.
     */
    public function openingDifference(): int
    {
        return Account::query()->get(['opening_balance', 'opening_balance_type'])->sum(fn (Account $a) => $a->openingPaise())
            + \App\Models\StockItem::query()->get(['opening_value'])->sum(fn ($i) => Money::toPaise($i->opening_value));
    }

    /** Profit (negative = credit = profit) from income/expense ledgers in earlier financial years. */
    public function accumulatedProfit(Collection $balances): int
    {
        return $balances->sum('pnl_before_year');
    }

    /** Closing balance of one ledger as of a date (paise, + = Dr). */
    public function closingFor(Account $account, string $date): int
    {
        return $this->forPeriod($this->yearStart($date), $date)->firstWhere('account.id', $account->id)->closing ?? 0;
    }

    /** First day of the financial year that contains $date (or the books beginning date). */
    public function yearStart(string $date): string
    {
        $company = $this->current->get();
        $year = $company->financialYearFor($date);

        if ($year) {
            return $year->start_date->toDateString();
        }

        $d = Carbon::parse($date);

        return Carbon::create($d->month >= 4 ? $d->year : $d->year - 1, 4, 1)->toDateString();
    }

    /** Default report period: current financial year up to today. */
    public function defaultPeriod(): array
    {
        $year = $this->current->financialYear();
        $from = $year?->start_date ?? Carbon::create(now()->month >= 4 ? now()->year : now()->year - 1, 4, 1);
        $end = $year?->end_date ?? $from->copy()->addYear()->subDay();
        $to = now()->lt($end) ? now() : $end;
        if ($to->lt($from)) {
            $to = $end;
        }

        return [$from->toDateString(), $to->toDateString()];
    }

    /**
     * Roll ledger balances up the group tree.
     *
     * @return Collection<int, object> groups in tree order with depth, opening, debit, credit, closing, ledgers
     */
    public function groupTree(Collection $balances): Collection
    {
        $groups = AccountGroup::tree();
        $byGroup = $balances->groupBy(fn ($b) => $b->account->account_group_id);
        $nodes = [];

        foreach ($groups as $group) {
            $nodes[$group->id] = (object) [
                'group' => $group, 'depth' => $group->depth, 'opening' => 0, 'debit' => 0, 'credit' => 0, 'closing' => 0,
                'ledgers' => $byGroup->get($group->id, collect()),
            ];
        }

        // Add each group's own ledgers to it and all of its ancestors.
        foreach ($groups as $group) {
            foreach ($nodes[$group->id]->ledgers as $b) {
                $id = $group->id;
                while ($id) {
                    $n = $nodes[$id];
                    $n->opening += $b->opening;
                    $n->debit += $b->debit;
                    $n->credit += $b->credit;
                    $n->closing += $b->closing;
                    $id = $n->group->parent_id;
                }
            }
        }

        return collect(array_values($nodes));
    }

    public static function money(int $paise): string
    {
        return Money::format($paise, paiseInput: true);
    }
}
