<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\Company;
use App\Support\Books;
use App\Support\Stock;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class ReportController extends Controller
{
    protected const TRADING_INCOME = ['SALES_ACCOUNTS', 'DIRECT_INCOMES'];

    protected const TRADING_EXPENSE = ['PURCHASE_ACCOUNTS', 'DIRECT_EXPENSES'];

    public function index(Company $company)
    {
        $this->authorizeCompany($company);

        return view('reports.index', compact('company'));
    }

    public function trialBalance(Request $request, Company $company)
    {
        $this->authorizeCompany($company);

        $books = new Books($company);
        $to = $this->dateParam($request, 'to', $books->financialYear()[1]);

        $groups = $books->closingBalances($to)
            ->filter(fn (Account $a) => $a->balance != 0)
            ->groupBy(fn (Account $a) => $a->primary->name)
            ->sortKeys();

        $yearStart = Books::yearStartFor($to);

        // Earlier years' profit sits in the Profit & Loss A/c (a credit when it was a profit),
        // and the stock carried into this year is shown as Opening Stock.
        $priorProfitAndLoss = -$this->retainedProfit($books, $yearStart);
        $openingStock = (new Stock($company))->closingValue($yearStart->subDay());

        $accounts = $groups->flatten(1);
        $totalDebit = round($accounts->where('balance', '>', 0)->sum('balance') + max($priorProfitAndLoss, 0) + $openingStock, 2);
        $totalCredit = round(-$accounts->where('balance', '<', 0)->sum('balance') + max(-$priorProfitAndLoss, 0), 2);

        return view('reports.trial-balance', [
            'company' => $company,
            'to' => $to,
            'groups' => $groups,
            'totalDebit' => $totalDebit,
            'totalCredit' => $totalCredit,
            'priorProfitAndLoss' => $priorProfitAndLoss,
            'openingStock' => $openingStock,
            'openingDifference' => $books->openingDifference(),
        ]);
    }

    public function profitAndLoss(Request $request, Company $company)
    {
        $this->authorizeCompany($company);

        $books = new Books($company);
        $to = $this->dateParam($request, 'to', $books->financialYear()[1]);

        $pl = $this->profitAndLossFigures($books, $to);

        return view('reports.profit-and-loss', ['company' => $company, 'to' => $to] + $pl);
    }

    public function balanceSheet(Request $request, Company $company)
    {
        $this->authorizeCompany($company);

        $books = new Books($company);
        $to = $this->dateParam($request, 'to', $books->financialYear()[1]);
        $accounts = $books->closingBalances($to);

        $assets = $this->byPrimary($accounts->where('primary.nature', 'assets'), 1);
        $closingStock = (new Stock($company))->closingValue($to);
        if ($closingStock != 0) {
            $assets->push($this->stockLine('Closing Stock', $closingStock));
        }
        $liabilities = $this->byPrimary($accounts->where('primary.nature', 'liabilities'), -1);
        $netProfit = $this->profitAndLossFigures($books, $to)['netProfit'];
        $retained = $this->retainedProfit($books, Books::yearStartFor($to));

        $totalAssets = round($assets->sum('total'), 2);
        $totalLiabilities = round($liabilities->sum('total') + $retained + $netProfit, 2);

        return view('reports.balance-sheet', [
            'company' => $company,
            'to' => $to,
            'assets' => $assets,
            'liabilities' => $liabilities,
            'netProfit' => $netProfit,
            'retained' => $retained,
            'totalAssets' => $totalAssets,
            'totalLiabilities' => $totalLiabilities,
            // Vouchers always balance, so any gap comes from opening balances.
            'difference' => round($totalAssets - $totalLiabilities, 2),
        ]);
    }

    /** Cash and cash equivalents. */
    public const CASH_GROUPS = ['CASH_IN_HAND', 'BANK_ACCOUNTS', 'BANK_OD'];

    public const INVESTING_GROUPS = ['FIXED_ASSETS', 'INVESTMENTS'];

    public const FINANCING_GROUPS = ['CAPITAL_ACCOUNT', 'SECURED_LOANS', 'UNSECURED_LOANS'];

    /**
     * Cash Flow: every movement in cash/bank ledgers, attributed to the other
     * ledgers of the same voucher and grouped as operating, investing or financing.
     */
    public function cashFlow(Request $request, Company $company)
    {
        $this->authorizeCompany($company);

        $books = new Books($company);
        [$fyStart, $fyEnd] = $books->financialYear();
        $from = $this->dateParam($request, 'from', $fyStart);
        $to = $this->dateParam($request, 'to', $fyEnd);

        $accounts = $company->accounts()->get()->keyBy('id');
        $isCash = $accounts->map(fn (Account $a) => $books->isUnder($a, self::CASH_GROUPS));
        $cashIds = $isCash->filter()->keys();

        $cashOn = fn (CarbonImmutable $date) => round($books->closingBalances($date)->whereIn('id', $cashIds)->sum('balance'), 2);
        $opening = $cashOn($from->subDay());
        $closing = $cashOn($to);

        $voucherIds = \App\Models\VoucherEntry::query()
            ->join('vouchers', 'vouchers.id', '=', 'voucher_entries.voucher_id')
            ->where('vouchers.company_id', $company->id)
            ->whereDate('vouchers.date', '>=', $from->toDateString())
            ->whereDate('vouchers.date', '<=', $to->toDateString())
            ->whereIn('voucher_entries.account_id', $cashIds)
            ->distinct()->pluck('voucher_entries.voucher_id');

        // Non-cash side of those vouchers: a credit brought cash in, a debit took it out.
        $lines = \App\Models\VoucherEntry::whereIn('voucher_id', $voucherIds)
            ->whereNotIn('account_id', $cashIds)
            ->get()
            ->groupBy('account_id')
            ->map(function ($entries, $accountId) use ($accounts, $books) {
                $account = $accounts[$accountId];

                return (object) [
                    'account' => $account,
                    'section' => $books->isUnder($account, self::INVESTING_GROUPS) ? 'investing'
                        : ($books->isUnder($account, self::FINANCING_GROUPS) ? 'financing' : 'operating'),
                    'inflow' => round((float) $entries->sum('credit'), 2),
                    'outflow' => round((float) $entries->sum('debit'), 2),
                ];
            })
            ->sortBy(fn ($l) => $l->account->name)
            ->values();

        $sections = collect(['operating' => 'Operating activities', 'investing' => 'Investing activities', 'financing' => 'Financing activities'])
            ->map(fn ($label, $key) => (object) [
                'label' => $label,
                'lines' => $in = $lines->where('section', $key)->values(),
                'inflow' => round($in->sum('inflow'), 2),
                'outflow' => round($in->sum('outflow'), 2),
                'net' => round($in->sum('inflow') - $in->sum('outflow'), 2),
            ]);

        $net = round($sections->sum('net'), 2);

        return view('reports.cash-flow', compact('company', 'from', 'to', 'opening', 'closing', 'sections', 'net'));
    }

    /**
     * Ratio Analysis, as in TallyPrime: liquidity, leverage, profitability and turnover.
     */
    public function ratios(Request $request, Company $company)
    {
        $this->authorizeCompany($company);

        $books = new Books($company);
        $to = $this->dateParam($request, 'to', $books->financialYear()[1]);
        $yearStart = Books::yearStartFor($to);
        $accounts = $books->closingBalances($to);

        // Debit-positive total of ledgers under these groups.
        $sum = fn (array $codes) => round($accounts->filter(fn (Account $a) => $books->isUnder($a, $codes))->sum('balance'), 2);

        $stock = (new Stock($company))->closingValue($to);
        $pl = $this->profitAndLossFigures($books, $to);
        $sales = round(-$sum(['SALES_ACCOUNTS']), 2);
        $purchases = $sum(['PURCHASE_ACCOUNTS']);
        $netProfit = (float) $pl['netProfit'];
        $equity = round(-$sum(['CAPITAL_ACCOUNT']) + $this->retainedProfit($books, $yearStart) + $netProfit, 2);

        $currentAssets = round($sum(['CURRENT_ASSETS']) + $stock, 2);
        $currentLiabilities = round(-$sum(['CURRENT_LIABILITIES']), 2);
        $debtors = $sum(['SUNDRY_DEBTORS']);
        $creditors = round(-$sum(['SUNDRY_CREDITORS']), 2);
        $loans = round(-$sum(['LOANS_LIABILITY']), 2);
        $days = max(1, $yearStart->diffInDays($to) + 1);

        $div = fn (float $a, float $b) => $b == 0.0 ? null : $a / $b;

        $figures = [
            'Working capital' => ['₹', round($currentAssets - $currentLiabilities, 2), 'Current assets − current liabilities'],
            'Cash & bank' => ['₹', $sum(self::CASH_GROUPS), 'Cash-in-hand, bank and OD balances'],
            'Sundry debtors' => ['₹', $debtors, 'Owed by customers'],
            'Sundry creditors' => ['₹', $creditors, 'Owed to suppliers'],
            'Sales' => ['₹', $sales, 'Sales accounts for the year'],
            'Purchases' => ['₹', $purchases, 'Purchase accounts for the year'],
            'Closing stock' => ['₹', $stock, 'At weighted average cost'],
            'Net profit' => ['₹', $netProfit, 'From the Profit & Loss'],
        ];
        $ratios = [
            'Current ratio' => ['x', $div($currentAssets, $currentLiabilities), 'Current assets ÷ current liabilities (healthy above 1.33)'],
            'Quick ratio' => ['x', $div($currentAssets - $stock, $currentLiabilities), '(Current assets − stock) ÷ current liabilities'],
            'Debt / equity' => ['x', $div($loans, $equity), 'Loans ÷ (capital + reserves + profit)'],
            'Gross profit %' => ['%', $div((float) $pl['grossProfit'] * 100, $sales), 'Gross profit ÷ sales'],
            'Net profit %' => ['%', $div($netProfit * 100, $sales), 'Net profit ÷ sales'],
            'Return on investment %' => ['%', $div($netProfit * 100, $equity), 'Net profit ÷ (capital + reserves + profit)'],
            'Receivable turnover (days)' => ['days', $div($debtors * $days, $sales), 'Debtors ÷ sales × days in the period'],
            'Payable turnover (days)' => ['days', $div($creditors * $days, $purchases), 'Creditors ÷ purchases × days in the period'],
            'Inventory turnover' => ['x', $div($sales, $stock), 'Sales ÷ closing stock'],
        ];

        return view('reports.ratios', compact('company', 'to', 'figures', 'ratios'));
    }

    public function ledger(Request $request, Company $company)
    {
        $this->authorizeCompany($company);

        $books = new Books($company);
        [$fyStart, $fyEnd] = $books->financialYear();

        $accounts = $company->accounts()->orderBy('name')->get();
        $account = $request->filled('account')
            ? $company->accounts()->findOrFail($request->integer('account'))
            : null;

        $from = $this->dateParam($request, 'from', $fyStart);
        $to = $this->dateParam($request, 'to', $fyEnd);

        $statement = $account ? $books->ledgerStatement($account, $from, $to) : null;

        return view('reports.ledger', compact('company', 'accounts', 'account', 'from', 'to', 'statement'));
    }

    /**
     * Trading and P&L figures. Income shown positive, expenses positive.
     */
    protected function profitAndLossFigures(Books $books, CarbonImmutable $to): array
    {
        $nominal = $books->closingBalances($to)
            ->filter(fn (Account $a) => in_array($a->primary->nature, ['income', 'expenses'], true));

        $section = fn (array $codes, int $sign) => $this->byPrimary(
            $nominal->filter(fn (Account $a) => in_array($a->primary->code, $codes, true)),
            $sign,
        );

        $tradingIncome = $section(self::TRADING_INCOME, -1);
        $tradingExpense = $section(self::TRADING_EXPENSE, 1);

        // Stock: opening stock is a trading expense, closing stock trading income.
        $stock = new Stock($books->company);
        if (($opening = $stock->closingValue(Books::yearStartFor($to)->subDay())) != 0) {
            $tradingExpense->prepend($this->stockLine('Opening Stock', $opening));
        }
        if (($closing = $stock->closingValue($to)) != 0) {
            $tradingIncome->push($this->stockLine('Closing Stock', $closing));
        }
        $indirectIncome = $section(['INDIRECT_INCOMES'], -1);
        $indirectExpense = $section(['INDIRECT_EXPENSES'], 1);

        $grossProfit = round($tradingIncome->sum('total') - $tradingExpense->sum('total'), 2);
        $netProfit = round($grossProfit + $indirectIncome->sum('total') - $indirectExpense->sum('total'), 2);

        return compact('tradingIncome', 'tradingExpense', 'indirectIncome', 'indirectExpense', 'grossProfit', 'netProfit');
    }

    /**
     * Profit of all earlier financial years (income/expense ledgers plus the
     * change in stock up to this year's start).
     */
    protected function retainedProfit(Books $books, CarbonImmutable $yearStart): float
    {
        if ($yearStart->lte($books->booksStart())) {
            return 0.0;
        }

        $stock = new Stock($books->company);
        $stockGain = $stock->closingValue($yearStart->subDay()) - $stock->openingValue();

        return round(-$books->priorNominalBalance($yearStart) + $stockGain, 2);
    }

    /**
     * Group ledgers under their primary group with totals. $sign flips credits to positive.
     *
     * @return Collection<int, object{name: string, accounts: Collection, total: float}>
     */
    protected function byPrimary(Collection $accounts, int $sign): Collection
    {
        return $accounts
            ->filter(fn (Account $a) => $a->balance != 0)
            ->groupBy(fn (Account $a) => $a->primary->name)
            ->sortKeys()
            ->map(fn (Collection $items, string $name) => (object) [
                'name' => $name,
                'accounts' => $items->map(fn (Account $a) => (object) [
                    'account' => $a,
                    'amount' => round($a->balance * $sign, 2),
                ]),
                'total' => round($items->sum('balance') * $sign, 2),
            ])
            ->values();
    }

    protected function stockLine(string $name, float $value): object
    {
        return (object) ['name' => $name, 'accounts' => collect(), 'total' => round($value, 2)];
    }

    protected function dateParam(Request $request, string $key, CarbonImmutable $default): CarbonImmutable
    {
        $request->validate([$key => ['nullable', 'date']]);

        return $request->filled($key) ? CarbonImmutable::parse($request->input($key)) : $default;
    }
}
