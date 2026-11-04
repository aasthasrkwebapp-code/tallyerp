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
