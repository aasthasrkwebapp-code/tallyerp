<?php

namespace App\Services\Reports;

use App\Services\Inventory\StockLedger;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Funds flow statement: where long-term funds came from and went to, and
 * the resulting change in working capital.
 *
 * Working capital is current assets (with closing stock) less current
 * liabilities. Every other balance sheet group is long-term: a fall in a
 * long-term asset or a rise in a long-term liability is a source of funds,
 * the reverse is an application. Profit is a source and a loss an
 * application. Sources less applications equals the change in working
 * capital; the statement checks that it does.
 */
class FundsFlow
{
    protected const CURRENT = ['current_assets', 'current_liabilities'];

    public function __construct(protected AccountBalances $balances, protected FinancialStatements $statements, protected StockLedger $stock) {}

    /**
     * @return array{sources: Collection, applications: Collection, net_profit: int, total_sources: int, total_applications: int,
     *               working_capital: array{rows: Collection, opening: int, closing: int, change: int}, difference: int}
     */
    public function statement(string $from, string $to): array
    {
        $rows = $this->balances->forPeriod($from, $to)->filter(fn ($r) => in_array($r->account->group->nature, ['asset', 'liability'], true));
        $tree = $this->balances->groupTree($rows);
        $nodes = $tree->keyBy(fn ($n) => $n->group->id);
        $netProfit = $this->statements->profitAndLoss($from, $to)['net_profit'];

        // Long-term groups: primary balance sheet groups other than current assets and liabilities.
        $longTerm = $tree->filter(fn ($n) => $n->group->parent_id === null && in_array($n->group->nature, ['asset', 'liability'], true)
            && ! in_array($n->group->system_key, self::CURRENT, true));

        $sources = collect();
        $applications = collect();
        foreach ($longTerm as $node) {
            $change = $node->closing - $node->opening; // + = more debit
            if ($change === 0) {
                continue;
            }
            $ledgers = $this->ledgerChanges($node, $nodes);
            $item = (object) ['group' => $node->group, 'opening' => $node->opening, 'closing' => $node->closing, 'amount' => abs($change), 'ledgers' => $ledgers];
            ($change < 0 ? $sources : $applications)->push($item);
        }

        $workingCapital = $this->workingCapital($tree, $nodes, $from, $to);
        $totalSources = $sources->sum('amount') + max($netProfit, 0);
        $totalApplications = $applications->sum('amount') + max(-$netProfit, 0);

        return [
            'sources' => $sources->values(),
            'applications' => $applications->values(),
            'net_profit' => $netProfit,
            'total_sources' => $totalSources,
            'total_applications' => $totalApplications,
            'working_capital' => $workingCapital,
            'difference' => $totalSources - $totalApplications - $workingCapital['change'],
        ];
    }

    /**
     * Current asset and liability groups (one level below the primary group), their own ledgers,
     * and stock, with opening and closing working capital effect (+ adds to working capital).
     */
    protected function workingCapital(Collection $tree, Collection $nodes, string $from, string $to): array
    {
        $out = collect();
        foreach (self::CURRENT as $key) {
            $primary = $tree->first(fn ($n) => $n->group->system_key === $key);
            if (! $primary) {
                continue;
            }
            // Liabilities reduce working capital, so their (credit) balance is used as it is: Dr positive.
            foreach ($tree->filter(fn ($n) => $n->group->parent_id === $primary->group->id) as $child) {
                $out->push((object) ['label' => $child->group->name, 'side' => $primary->group->nature, 'group_id' => $child->group->id, 'account_id' => null,
                    'opening' => $child->opening, 'closing' => $child->closing, 'change' => $child->closing - $child->opening]);
            }
            foreach ($primary->ledgers as $b) {
                $out->push((object) ['label' => $b->account->name, 'side' => $primary->group->nature, 'group_id' => null, 'account_id' => $b->account->id,
                    'opening' => $b->opening, 'closing' => $b->closing, 'change' => $b->closing - $b->opening]);
            }
        }

        if ($this->stock->active()) {
            $openingStock = $this->stock->valueAt(Carbon::parse($from)->subDay()->toDateString());
            $closingStock = $this->stock->valueAt($to);
            if ($openingStock || $closingStock) {
                $out->push((object) ['label' => 'Closing stock', 'side' => 'asset', 'group_id' => null, 'account_id' => null,
                    'opening' => $openingStock, 'closing' => $closingStock, 'change' => $closingStock - $openingStock]);
            }
        }

        $out = $out->filter(fn ($r) => $r->opening !== 0 || $r->closing !== 0)->values();

        return [
            'rows' => $out,
            'opening' => $out->sum('opening'),
            'closing' => $out->sum('closing'),
            'change' => $out->sum('change'),
        ];
    }

    /** Ledgers under a group (all depths) whose balance changed: + = more debit. */
    protected function ledgerChanges(object $node, Collection $nodes): Collection
    {
        $ids = [$node->group->id];
        foreach ($nodes as $n) {
            if ($this->isDescendant($n, $node->group->id, $nodes)) {
                $ids[] = $n->group->id;
            }
        }

        return $nodes->only(array_unique($ids))->flatMap(fn ($n) => $n->ledgers)
            ->map(fn ($b) => (object) ['account' => $b->account, 'change' => $b->closing - $b->opening])
            ->filter(fn ($l) => $l->change !== 0)->sortBy(fn ($l) => $l->account->name)->values();
    }

    protected function isDescendant(object $node, int $ancestorId, Collection $nodes): bool
    {
        $parent = $node->group->parent_id;
        while ($parent) {
            if ($parent === $ancestorId) {
                return true;
            }
            $parent = $nodes->get($parent)?->group->parent_id;
        }

        return false;
    }
}
