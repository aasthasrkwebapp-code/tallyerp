<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\Company;
use App\Support\Books;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Yearly budgets for income and expense ledgers, and budget vs actual.
 */
class BudgetController extends Controller
{
    public function index(Request $request, Company $company)
    {
        $this->authorizeCompany($company);

        $request->validate(['to' => ['nullable', 'date']]);
        $books = new Books($company);
        [$fyStart, $fyEnd] = $books->financialYear();
        $to = $request->filled('to') ? min(CarbonImmutable::parse($request->input('to')), $fyEnd) : $fyEnd;
        if ($to->lt($fyStart)) {
            $to = $fyEnd;
        }

        $budgets = $company->budgets()->where('fy_start_year', $fyStart->year)->pluck('amount', 'account_id');
        $balances = $books->closingBalances($to)->keyBy('id');

        // Share of the year elapsed, to compare actuals against a pro-rated budget.
        $elapsed = min(1, ($fyStart->diffInDays($to) + 1) / ($fyStart->diffInDays($fyEnd) + 1));

        $rows = $balances
            ->filter(fn (Account $a) => $books->isNominal($a))
            ->map(function (Account $a) use ($budgets, $elapsed) {
                $income = $a->primary->nature === 'income';
                $actual = round($income ? -$a->balance : $a->balance, 2);
                $budget = (float) ($budgets[$a->id] ?? 0);
                $toDate = round($budget * $elapsed, 2);

                return (object) [
                    'account' => $a,
                    'income' => $income,
                    'budget' => $budget,
                    'budget_to_date' => $toDate,
                    'actual' => $actual,
                    // Positive = favourable: more income or less spending than planned.
                    'variance' => round($income ? $actual - $toDate : $toDate - $actual, 2),
                    'used' => $budget > 0 ? round($actual / $budget * 100, 1) : null,
                ];
            })
            ->filter(fn ($r) => $r->budget != 0 || $r->actual != 0)
            ->sortBy(fn ($r) => ($r->income ? '0' : '1').$r->account->name)
            ->values();

        return view('budgets.index', compact('company', 'rows', 'to', 'fyStart', 'fyEnd', 'elapsed'));
    }

    public function edit(Company $company)
    {
        $this->authorizeCompany($company);

        $books = new Books($company);
        [$fyStart] = $books->financialYear();

        $accounts = $company->accounts()->with('group')->orderBy('name')->get()
            ->filter(fn (Account $a) => $books->isNominal($a))
            ->values();

        return view('budgets.edit', [
            'company' => $company,
            'accounts' => $accounts,
            'budgets' => $company->budgets()->where('fy_start_year', $fyStart->year)->pluck('amount', 'account_id'),
            'lastYear' => $this->actualsFor($company, $books, $fyStart->subYear()),
        ]);
    }

    public function update(Request $request, Company $company)
    {
        $this->authorizeCompany($company);

        $data = $request->validate([
            'budgets' => ['array'],
            'budgets.*' => ['nullable', 'numeric', 'min:0', 'max:9999999999999.99'],
        ]);

        $books = new Books($company);
        $year = $books->financialYear()[0]->year;
        $allowed = $company->accounts()->get()->filter(fn (Account $a) => $books->isNominal($a))->pluck('id')->all();

        DB::transaction(function () use ($company, $data, $year, $allowed) {
            foreach ($data['budgets'] ?? [] as $accountId => $amount) {
                if (! in_array((int) $accountId, $allowed)) {
                    continue;
                }

                if ($amount === null || (float) $amount == 0) {
                    $company->budgets()->where('account_id', $accountId)->where('fy_start_year', $year)->delete();
                } else {
                    $company->budgets()->updateOrCreate(
                        ['account_id' => (int) $accountId, 'fy_start_year' => $year],
                        ['amount' => round((float) $amount, 2)],
                    );
                }
            }
        });

        return redirect()
            ->route('companies.budgets.index', $company)
            ->with('success', 'Budgets saved for '.$company->financial_year.'.');
    }

    /**
     * Last year's actual per ledger (positive), to help set this year's budget.
     *
     * @return array<int, float>
     */
    protected function actualsFor(Company $company, Books $books, CarbonImmutable $yearStart): array
    {
        if ($yearStart->lt($books->booksStart())) {
            return [];
        }

        return $books->closingBalances($yearStart->addYear()->subDay())
            ->filter(fn (Account $a) => $books->isNominal($a) && $a->balance != 0)
            ->mapWithKeys(fn (Account $a) => [$a->id => abs($a->balance)])
            ->all();
    }
}
