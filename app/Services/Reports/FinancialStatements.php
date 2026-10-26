<?php

namespace App\Services\Reports;

use App\Models\AccountGroup;
use App\Services\Inventory\StockLedger;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Trial balance, profit & loss and balance sheet built from AccountBalances.
 * Every figure comes from posted voucher entries and ledger opening balances,
 * plus stock values from StockLedger (opening stock of the year in the trial
 * balance, opening and closing stock in trading, closing stock as an asset).
 */
class FinancialStatements
{
    public function __construct(protected AccountBalances $balances, protected StockLedger $stock) {}

    /**
     * Stock values for statements: opening stock as at books beginning (from the
     * items), stock at the start of the year containing $date and stock at $date.
     *
     * @return array{books:int, year:int}
     */
    protected function stockOpenings(string $date): array
    {
        if (! $this->stock->active()) {
            return ['books' => 0, 'year' => 0];
        }

        return [
            'books' => $this->stock->openingValue(),
            'year' => $this->stockBefore($this->balances->yearStart($date)),
        ];
    }

    /** Stock value at the end of the day before $date. */
    protected function stockBefore(string $date): int
    {
        return $this->stock->active() ? $this->stock->valueAt(Carbon::parse($date)->subDay()->toDateString()) : 0;
    }

    public function trialBalance(string $from, string $to): array
    {
        $rows = $this->balances->forPeriod($from, $to);
        $tree = $this->balances->groupTree($rows);
        $stock = $this->stockOpenings($from);
        // Stock gained in earlier years is profit of those years (credit).
        $accumulated = $this->balances->accumulatedProfit($rows) - ($stock['year'] - $stock['books']);
        $difference = $this->balances->openingDifference();
        $openingStock = $stock['year'];

        $totals = [
            'opening' => $rows->sum('opening') + $accumulated + $openingStock - $difference,
            'debit' => $rows->sum('debit'),
            'credit' => $rows->sum('credit'),
            'closing_dr' => $rows->sum(fn ($r) => max($r->closing, 0)) + max($accumulated, 0) + $openingStock + max(-$difference, 0),
            'closing_cr' => $rows->sum(fn ($r) => max(-$r->closing, 0)) + max(-$accumulated, 0) + max($difference, 0),
        ];

        return compact('rows', 'tree', 'accumulated', 'difference', 'openingStock', 'totals');
    }

    /**
     * @return array{sections: array, gross_profit:int, net_profit:int}
     *   Amounts are positive for income/profit.
     */
    public function profitAndLoss(string $from, string $to): array
    {
        $rows = $this->balances->forPeriod($from, $to);
        $tree = $this->balances->groupTree($rows)->keyBy(fn ($n) => $n->group->id);

        $section = function (string $nature, bool $trading) use ($tree) {
            return $tree->filter(fn ($n) => $n->group->parent_id === null
                && $n->group->nature === $nature
                && $n->group->affects_gross_profit === $trading)
                ->map(fn ($n) => $this->withMovement($n, $tree, $nature))
                ->filter(fn ($n) => $n->amount !== 0 || $n->has_activity)
                ->values();
        };

        $sections = [
            'trading_income' => $section('income', true),
            'trading_expense' => $section('expense', true),
            'other_income' => $section('income', false),
            'other_expense' => $section('expense', false),
        ];

        $sum = fn (Collection $c) => $c->sum('amount');
        $openingStock = $this->stockBefore($from);
        $closingStock = $this->stock->active() ? $this->stock->valueAt($to) : 0;
        $grossProfit = $sum($sections['trading_income']) + $closingStock - $sum($sections['trading_expense']) - $openingStock;
        $netProfit = $grossProfit + $sum($sections['other_income']) - $sum($sections['other_expense']);

        return [
            'sections' => $sections,
            'opening_stock' => $openingStock,
            'closing_stock' => $closingStock,
            'gross_profit' => $grossProfit,
            'net_profit' => $netProfit,
            'totals' => array_map($sum, $sections),
        ];
    }

    public function balanceSheet(string $asOf): array
    {
        $yearStart = $this->balances->yearStart($asOf);
        $rows = $this->balances->forPeriod($yearStart, $asOf);
        $tree = $this->balances->groupTree($rows)->keyBy(fn ($n) => $n->group->id);

        $side = fn (string $nature) => $tree->filter(fn ($n) => $n->group->parent_id === null && $n->group->nature === $nature)
            ->map(function ($n) use ($nature, $tree) {
                $n->amount = $nature === 'asset' ? $n->closing : -$n->closing;
                $n->children = $this->children($n, $tree, fn ($c) => $nature === 'asset' ? $c : -$c);

                return $n;
            })
            ->filter(fn ($n) => $n->amount !== 0 || $n->ledgers->isNotEmpty() || $n->children->isNotEmpty())
            ->values();

        $liabilities = $side('liability');
        $assets = $side('asset');

        // Profit is a credit (negative) balance on income/expense ledgers.
        // Stock adds to profit: this year's change in the current period, earlier changes in previous years.
        $stock = $this->stockOpenings($asOf);
        $closingStock = $this->stock->active() ? $this->stock->valueAt($asOf) : 0;
        $currentProfit = -$rows->filter(fn ($r) => in_array($r->account->group->nature, ['income', 'expense'], true))->sum('closing')
            + $closingStock - $stock['year'];
        $accumulatedProfit = -$this->balances->accumulatedProfit($rows) + $stock['year'] - $stock['books'];
        $difference = $this->balances->openingDifference();

        $totalLiabilities = $liabilities->sum('amount') + $currentProfit + $accumulatedProfit + $difference;
        $totalAssets = $assets->sum('amount') + $closingStock;

        return compact('liabilities', 'assets', 'closingStock', 'currentProfit', 'accumulatedProfit', 'difference', 'totalLiabilities', 'totalAssets', 'yearStart');
    }

    /** Attach a positive "amount" for a P&L group (income Cr positive, expense Dr positive). */
    protected function withMovement(object $node, Collection $tree, string $nature): object
    {
        $sign = $nature === 'income' ? -1 : 1;
        $node->amount = $sign * ($node->debit - $node->credit);
        $node->has_activity = $node->debit !== 0 || $node->credit !== 0;
        $node->children = $this->children($node, $tree, fn ($v) => $v, fn ($b) => $sign * ($b->debit - $b->credit), fn ($n) => $sign * ($n->debit - $n->credit));

        return $node;
    }

    /**
     * Sub-groups and ledgers directly under a node, each with an "amount".
     */
    protected function children(object $node, Collection $tree, callable $flip, ?callable $ledgerAmount = null, ?callable $groupAmount = null): Collection
    {
        $ledgerAmount ??= fn ($b) => $flip($b->closing);
        $groupAmount ??= fn ($n) => $flip($n->closing);

        $groups = $tree->filter(fn ($n) => $n->group->parent_id === $node->group->id)
            ->map(function ($n) use ($tree, $flip, $ledgerAmount, $groupAmount) {
                $n->amount = $groupAmount($n);
                $n->children = $this->children($n, $tree, $flip, $ledgerAmount, $groupAmount);

                return (object) ['type' => 'group', 'name' => $n->group->name, 'id' => $n->group->id, 'amount' => $n->amount, 'node' => $n];
            })
            ->filter(fn ($c) => $c->amount !== 0 || $c->node->ledgers->isNotEmpty())
            ->values();

        $ledgers = $node->ledgers
            ->map(fn ($b) => (object) ['type' => 'ledger', 'name' => $b->account->name, 'id' => $b->account->id, 'amount' => $ledgerAmount($b)])
            ->filter(fn ($c) => $c->amount !== 0)
            ->values();

        return $groups->concat($ledgers)->values();
    }
}
