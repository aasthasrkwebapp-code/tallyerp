<?php

namespace App\Services\Reports;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Profit & loss or balance sheet for several periods side by side.
 *
 * Every column is the ordinary statement for its period (or "as on" its
 * last day for the balance sheet), so the figures always agree with the
 * single-period reports. Rows are matched across columns by group or ledger.
 */
class PeriodComparison
{
    public const MODES = [
        'previous' => 'This period and the same period last year',
        'month' => 'Month by month',
        'quarter' => 'Quarter by quarter',
        'year' => 'Financial year by year',
    ];

    public const MAX_COLUMNS = 12;

    public function __construct(protected FinancialStatements $statements, protected AccountBalances $balances) {}

    /**
     * Columns for the period from $from to $to.
     *
     * @return array<int, array{from: string, to: string, label: string}>
     */
    public function columns(string $mode, string $from, string $to): array
    {
        $start = Carbon::parse($from);
        $end = Carbon::parse($to);

        if ($mode === 'previous') {
            $prevFrom = $start->copy()->subYearNoOverflow();
            $prevTo = $end->copy()->subYearNoOverflow();

            return [
                ['from' => $prevFrom->toDateString(), 'to' => $prevTo->toDateString(), 'label' => $this->label($prevFrom, $prevTo)],
                ['from' => $start->toDateString(), 'to' => $end->toDateString(), 'label' => $this->label($start, $end)],
            ];
        }

        $columns = [];
        $cursor = $start->copy();
        while ($cursor->lte($end) && count($columns) <= self::MAX_COLUMNS) {
            $stop = match ($mode) {
                'month' => $cursor->copy()->endOfMonth(),
                'quarter' => $this->quarterEnd($cursor),
                default => Carbon::parse($this->balances->yearStart($cursor->toDateString()))->addYear()->subDay(),
            };
            $stop = $stop->gt($end) ? $end->copy() : $stop;
            $columns[] = ['from' => $cursor->toDateString(), 'to' => $stop->toDateString(), 'label' => match ($mode) {
                'month' => $cursor->format('M Y'),
                'quarter' => 'Q'.$this->quarterNumber($cursor).' '.$this->yearLabel($cursor),
                default => 'FY '.$this->yearLabel($cursor),
            }];
            $cursor = $stop->copy()->addDay();
        }

        return $columns;
    }

    /**
     * Comparative profit & loss.
     *
     * @param  array<int, array{from: string, to: string, label: string}>  $columns
     * @return array{columns: array, rows: Collection}
     */
    public function profitAndLoss(array $columns, bool $detailed): array
    {
        $statements = array_map(fn ($c) => $this->statements->profitAndLoss($c['from'], $c['to']), $columns);
        $rows = collect();

        $sections = [
            ['trading_income', 'Trading income', 'closing_stock', 'Add: closing stock'],
            ['trading_expense', 'Less: direct costs', 'opening_stock', 'Add: opening stock'],
        ];
        // Amounts are shown positive under each heading, as in the single-period statement.
        foreach ($sections as [$key, $title, $stockKey, $stockLabel]) {
            $rows->push($this->heading($title));
            $this->sectionRows($rows, array_map(fn ($s) => $s['sections'][$key], $statements), $detailed);
            $stock = array_column($statements, $stockKey);
            if (array_filter($stock)) {
                // Over the whole span, stock is the first opening and the last closing, not a sum.
                $row = $this->line($stockLabel, $stock, 1);
                $row->total = $stockKey === 'closing_stock' ? end($stock) : $stock[0];
                $rows->push($row);
            }
        }
        $rows->push($this->line('Gross profit / (loss)', array_column($statements, 'gross_profit'), 0, 'total'));

        foreach (['other_income' => 'Add: indirect income', 'other_expense' => 'Less: indirect expenses'] as $key => $title) {
            $rows->push($this->heading($title));
            $this->sectionRows($rows, array_map(fn ($s) => $s['sections'][$key], $statements), $detailed);
        }
        $rows->push($this->line('Net profit / (loss)', array_column($statements, 'net_profit'), 0, 'grand'));

        return ['columns' => $columns, 'rows' => $rows];
    }

    /**
     * Comparative balance sheet, as on the last day of each column.
     *
     * @return array{columns: array, rows: Collection}
     */
    public function balanceSheet(array $columns, bool $detailed): array
    {
        $statements = array_map(fn ($c) => $this->statements->balanceSheet($c['to']), $columns);
        $rows = collect();

        $rows->push($this->heading('Liabilities'));
        $this->sectionRows($rows, array_column($statements, 'liabilities'), $detailed);
        $rows->push($this->line('Profit & Loss A/c', array_map(fn ($s) => $s['currentProfit'] + $s['accumulatedProfit'], $statements), 1));
        $difference = array_column($statements, 'difference');
        if (array_filter($difference)) {
            $rows->push($this->line('Difference in opening balances', $difference, 1));
        }
        $rows->push($this->line('Total liabilities', array_column($statements, 'totalLiabilities'), 0, 'total'));

        $rows->push($this->heading('Assets'));
        $this->sectionRows($rows, array_column($statements, 'assets'), $detailed);
        $stock = array_column($statements, 'closingStock');
        if (array_filter($stock)) {
            $rows->push($this->line('Closing stock', $stock, 1));
        }
        $rows->push($this->line('Total assets', array_column($statements, 'totalAssets'), 0, 'total'));

        return ['columns' => $columns, 'rows' => $rows];
    }

    /**
     * Primary groups of one section across columns, matched by group id,
     * with their direct sub-groups and ledgers when detailed.
     *
     * @param  array<int, Collection>  $perColumn  statement nodes for each column
     */
    protected function sectionRows(Collection $rows, array $perColumn, bool $detailed): void
    {
        $count = count($perColumn);
        $groups = [];
        foreach ($perColumn as $i => $nodes) {
            foreach ($nodes as $node) {
                $id = $node->group->id;
                $groups[$id] ??= ['name' => $node->group->name, 'order' => $node->group->sort_order, 'amounts' => array_fill(0, $count, 0), 'children' => []];
                $groups[$id]['amounts'][$i] = $node->amount;
                foreach ($node->children as $child) {
                    $key = $child->type.':'.$child->id;
                    $groups[$id]['children'][$key] ??= ['name' => $child->name, 'type' => $child->type, 'id' => $child->id, 'amounts' => array_fill(0, $count, 0)];
                    $groups[$id]['children'][$key]['amounts'][$i] = $child->amount;
                }
            }
        }
        if ($groups === []) {
            $rows->push((object) ['label' => 'None', 'depth' => 1, 'kind' => 'empty', 'amounts' => array_fill(0, $count, null), 'total' => null, 'link' => null]);

            return;
        }

        uasort($groups, fn ($a, $b) => [$a['order'], $a['name']] <=> [$b['order'], $b['name']]);
        foreach ($groups as $id => $group) {
            $rows->push((object) ['label' => $group['name'], 'depth' => 1, 'kind' => 'group', 'amounts' => $group['amounts'], 'total' => array_sum($group['amounts']), 'link' => ['group', $id]]);
            if (! $detailed) {
                continue;
            }
            $children = collect($group['children'])->sortBy([['type', 'asc'], ['name', 'asc']]);
            foreach ($children as $child) {
                $rows->push((object) ['label' => $child['name'], 'depth' => 2, 'kind' => 'child', 'amounts' => $child['amounts'], 'total' => array_sum($child['amounts']), 'link' => [$child['type'], $child['id']]]);
            }
        }
    }

    protected function heading(string $label): object
    {
        return (object) ['label' => $label, 'depth' => 0, 'kind' => 'heading', 'amounts' => [], 'total' => null, 'link' => null];
    }

    protected function line(string $label, array $amounts, int $depth, string $kind = 'line'): object
    {
        return (object) ['label' => $label, 'depth' => $depth, 'kind' => $kind, 'amounts' => array_values($amounts), 'total' => array_sum($amounts), 'link' => null];
    }

    /** Change from the first to the last column, and the change in percent of the first (null when it was zero). */
    public static function change(array $amounts): array
    {
        if (count($amounts) < 2 || $amounts[0] === null) {
            return [null, null];
        }
        $first = $amounts[0];
        $diff = end($amounts) - $first;

        return [$diff, $first !== 0 ? round($diff * 100 / abs($first), 1) : null];
    }

    protected function quarterEnd(Carbon $date): Carbon
    {
        $yearStart = Carbon::parse($this->balances->yearStart($date->toDateString()));
        $months = $yearStart->diffInMonths($date->copy()->startOfMonth());

        return $yearStart->copy()->addMonthsNoOverflow((intdiv((int) $months, 3) + 1) * 3)->subDay();
    }

    protected function quarterNumber(Carbon $date): int
    {
        $yearStart = Carbon::parse($this->balances->yearStart($date->toDateString()));

        return intdiv((int) $yearStart->diffInMonths($date->copy()->startOfMonth()), 3) + 1;
    }

    /** "2026-27" for the financial year that contains $date. */
    protected function yearLabel(Carbon $date): string
    {
        $start = Carbon::parse($this->balances->yearStart($date->toDateString()));
        $end = $start->copy()->addYear()->subDay();

        return $start->year === $end->year ? (string) $start->year : $start->year.'-'.$end->format('y');
    }

    protected function label(Carbon $from, Carbon $to): string
    {
        return $from->format('d M Y').' – '.$to->format('d M Y');
    }
}
