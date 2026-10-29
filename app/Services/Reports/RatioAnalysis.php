<?php

namespace App\Services\Reports;

use Illuminate\Support\Carbon;

/**
 * Ratio analysis: the principal group balances and the usual ratios.
 *
 * Balances are as on the last day of the period; sales, purchases and
 * profit are for the period. All money is integer paise. A ratio whose
 * divisor is zero (or a negative net worth) is null and shown as "n/a".
 */
class RatioAnalysis
{
    /** key => [label, unit, how it is worked out] */
    public const RATIOS = [
        'current_ratio' => ['Current ratio', 'ratio', 'Current assets ÷ current liabilities'],
        'quick_ratio' => ['Quick ratio', 'ratio', '(Current assets − stock) ÷ current liabilities'],
        'debt_equity' => ['Debt / equity ratio', 'ratio', 'Loans (liability) ÷ net worth (capital, reserves and profit)'],
        'gross_profit_pct' => ['Gross profit %', 'percent', 'Gross profit ÷ sales × 100'],
        'net_profit_pct' => ['Net profit %', 'percent', 'Net profit ÷ sales × 100'],
        'operating_cost_pct' => ['Operating cost %', 'percent', '(Sales − net profit) ÷ sales × 100'],
        'receivable_days' => ['Receivable turnover in days', 'days', 'Sundry debtors ÷ sales × days in the period'],
        'payable_days' => ['Payable turnover in days', 'days', 'Sundry creditors ÷ purchases × days in the period'],
        'inventory_turnover' => ['Inventory turnover', 'times', 'Cost of goods sold ÷ average stock (opening + closing ÷ 2)'],
        'working_capital_turnover' => ['Working capital turnover', 'times', 'Sales ÷ working capital'],
        'return_on_investment' => ['Return on investment %', 'percent', 'Net profit ÷ net worth × 100'],
        'return_on_working_capital' => ['Return on working capital %', 'percent', 'Net profit ÷ working capital × 100'],
    ];

    /** key => label */
    public const GROUPS = [
        'working_capital' => 'Working capital (current assets − current liabilities)',
        'current_assets' => 'Current assets (including closing stock)',
        'current_liabilities' => 'Current liabilities',
        'cash' => 'Cash-in-hand',
        'bank' => 'Bank accounts',
        'bank_od' => 'Bank OD accounts',
        'debtors' => 'Sundry debtors',
        'creditors' => 'Sundry creditors',
        'stock' => 'Closing stock',
        'loans' => 'Loans (liability)',
        'net_worth' => 'Net worth (capital, reserves and profit)',
        'sales' => 'Sales accounts',
        'purchases' => 'Purchase accounts',
        'gross_profit' => 'Gross profit',
        'net_profit' => 'Net profit',
    ];

    public function __construct(protected FinancialStatements $statements, protected AccountBalances $balances) {}

    /** @return array{from: string, to: string, days: int, groups: array<string, int>, ratios: array<string, ?float>} */
    public function compute(string $from, string $to): array
    {
        $pl = $this->statements->profitAndLoss($from, $to);
        $bs = $this->statements->balanceSheet($to);
        $tree = $this->balances->groupTree($this->balances->forPeriod($this->balances->yearStart($to), $to))
            ->filter(fn ($n) => $n->group->system_key)->keyBy(fn ($n) => $n->group->system_key);
        $closing = fn (string $key) => $tree->get($key)?->closing ?? 0;
        $section = fn (string $section, string $key) => (int) $pl['sections'][$section]->filter(fn ($n) => $n->group->system_key === $key)->sum('amount');

        $stock = $bs['closingStock'] + $closing('stock_in_hand');
        $currentAssets = $closing('current_assets') + $bs['closingStock'];
        $currentLiabilities = -$closing('current_liabilities');
        $g = [
            'working_capital' => $currentAssets - $currentLiabilities,
            'current_assets' => $currentAssets,
            'current_liabilities' => $currentLiabilities,
            'cash' => $closing('cash_in_hand'),
            'bank' => $closing('bank_accounts'),
            'bank_od' => -$closing('bank_od'),
            'debtors' => $closing('sundry_debtors'),
            'creditors' => -$closing('sundry_creditors'),
            'stock' => $stock,
            'loans' => -$closing('loans_liability'),
            'net_worth' => -$closing('capital_account') + $bs['currentProfit'] + $bs['accumulatedProfit'],
            'sales' => $section('trading_income', 'sales_accounts'),
            'purchases' => $section('trading_expense', 'purchase_accounts'),
            'gross_profit' => $pl['gross_profit'],
            'net_profit' => $pl['net_profit'],
        ];

        $days = (int) Carbon::parse($from)->diffInDays(Carbon::parse($to)) + 1;
        $costOfSales = $pl['opening_stock'] + $pl['totals']['trading_expense'] - $pl['closing_stock'];
        $averageStock = intdiv($pl['opening_stock'] + $pl['closing_stock'], 2);
        $div = fn (int|float $a, int|float $b, float $times = 1) => $b > 0 ? round($a / $b * $times, 2) : null;

        $ratios = [
            'current_ratio' => $div($currentAssets, $currentLiabilities),
            'quick_ratio' => $div($currentAssets - $stock, $currentLiabilities),
            'debt_equity' => $div($g['loans'], $g['net_worth']),
            'gross_profit_pct' => $div($g['gross_profit'], $g['sales'], 100),
            'net_profit_pct' => $div($g['net_profit'], $g['sales'], 100),
            'operating_cost_pct' => $div($g['sales'] - $g['net_profit'], $g['sales'], 100),
            'receivable_days' => $div($g['debtors'], $g['sales'], $days),
            'payable_days' => $div($g['creditors'], $g['purchases'], $days),
            'inventory_turnover' => $div($costOfSales, $averageStock),
            'working_capital_turnover' => $div($g['sales'], $g['working_capital']),
            'return_on_investment' => $div($g['net_profit'], $g['net_worth'], 100),
            'return_on_working_capital' => $div($g['net_profit'], $g['working_capital'], 100),
        ];

        return ['from' => $from, 'to' => $to, 'days' => $days, 'groups' => $g, 'ratios' => $ratios];
    }

    public static function format(?float $value, string $unit): string
    {
        if ($value === null) {
            return 'n/a';
        }

        return match ($unit) {
            'ratio' => number_format($value, 2).' : 1',
            'percent' => number_format($value, 2).' %',
            'days' => number_format($value, 0).' days',
            default => number_format($value, 2).' times',
        };
    }
}
