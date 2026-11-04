<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\Company;
use App\Models\Currency;
use App\Models\Voucher;
use App\Support\Books;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Currencies, and revaluation of foreign-currency ledgers at closing rates.
 */
class ForexController extends Controller
{
    public function index(Company $company)
    {
        $this->authorizeCompany($company);

        $currencies = $company->currencies()->orderBy('code')->get()
            ->each(fn (Currency $c) => $c->ledgers_count = $company->accounts()->where('currency_code', $c->code)->count());

        return view('forex.currencies', compact('company', 'currencies'));
    }

    public function store(Request $request, Company $company)
    {
        $this->authorizeCompany($company);

        $request->merge(['code' => strtoupper(trim((string) $request->input('code')))]);
        $data = $request->validate([
            'code' => ['required', 'regex:/^[A-Z]{3}$/', 'not_in:'.$company->currency, Rule::unique('currencies')->where('company_id', $company->id)],
            'symbol' => ['nullable', 'string', 'max:5'],
            'name' => ['nullable', 'string', 'max:255'],
        ], ['code.not_in' => $company->currency.' is the base currency.']);

        [$symbol, $name] = Currency::COMMON[$data['code']] ?? [null, null];
        $company->currencies()->create([
            'code' => $data['code'],
            'symbol' => ($data['symbol'] ?? null) ?: $symbol,
            'name' => ($data['name'] ?? null) ?: $name,
        ]);

        return back()->with('success', $data['code'].' added.');
    }

    public function destroy(Company $company, Currency $currency)
    {
        $this->authorizeCompany($company);
        abort_unless((int) $currency->company_id === (int) $company->id, 404);

        if ($company->accounts()->where('currency_code', $currency->code)->exists()) {
            return back()->with('error', 'Ledgers use '.$currency->code.'.');
        }

        $currency->delete();

        return back()->with('success', $currency->code.' removed.');
    }

    public function revaluation(Request $request, Company $company)
    {
        $this->authorizeCompany($company);

        $request->validate(['to' => ['nullable', 'date'], 'rates' => ['nullable', 'array'], 'rates.*' => ['nullable', 'numeric', 'gt:0']]);
        $to = $this->asOn($request, $company);
        $rates = array_filter((array) $request->input('rates', []));

        $rows = $this->ledgerPositions($company, $to)->each(function (object $row) use ($rates) {
            $rate = isset($rates[$row->account->currency_code]) ? (float) $rates[$row->account->currency_code] : null;
            $row->revalued = $rate !== null ? round($row->foreign * $rate, 2) : null;
            $row->difference = $rate !== null ? round($row->revalued - $row->rupees, 2) : null;
        });

        $codes = $rows->pluck('account.currency_code')->unique()->sort()->values();

        return view('forex.revaluation', compact('company', 'to', 'rows', 'codes', 'rates'));
    }

    /**
     * Post the exchange difference so foreign ledgers show their value at the closing rate.
     */
    public function postRevaluation(Request $request, Company $company)
    {
        $this->authorizeCompany($company);

        [$fyStart, $fyEnd] = (new Books($company))->financialYear();
        $data = $request->validate([
            'to' => ['required', 'date', 'after_or_equal:'.$fyStart->toDateString(), 'before_or_equal:'.$fyEnd->toDateString()],
            'rates' => ['required', 'array'],
            'rates.*' => ['required', 'numeric', 'gt:0'],
        ]);
        $to = CarbonImmutable::parse($data['to']);

        $entries = [];
        foreach ($this->ledgerPositions($company, $to) as $row) {
            $rate = $data['rates'][$row->account->currency_code] ?? null;
            if ($rate === null) {
                continue;
            }

            $difference = round($row->foreign * (float) $rate - $row->rupees, 2);
            if ($difference != 0) {
                // Rupee-only adjustment: the foreign amount is unchanged.
                $entries[] = [
                    'account_id' => $row->account->id,
                    'debit' => max($difference, 0),
                    'credit' => max(-$difference, 0),
                    'currency_code' => $row->account->currency_code,
                    'foreign_amount' => 0,
                    'exchange_rate' => (float) $rate,
                ];
            }
        }

        if (! $entries) {
            throw ValidationException::withMessages(['rates' => 'Nothing to revalue at these rates.']);
        }

        $net = round(array_sum(array_column($entries, 'debit')) - array_sum(array_column($entries, 'credit')), 2);
        $gainLoss = $company->systemLedger('FOREX_GAIN_LOSS', 'Exchange Gain / Loss', 'INDIRECT_INCOMES');
        $entries[] = ['account_id' => $gainLoss->id, 'debit' => max(-$net, 0), 'credit' => max($net, 0)];

        $voucher = DB::transaction(function () use ($company, $to, $entries, $data) {
            $voucher = $company->vouchers()->create([
                'type' => 'journal',
                'number' => Voucher::nextNumber($company, 'journal', $to),
                'date' => $to->toDateString(),
                'reference' => 'Forex revaluation',
                'narration' => 'Revaluation at '.collect($data['rates'])->map(fn ($r, $c) => $c.' '.$r)->join(', '),
                'amount' => round(array_sum(array_column($entries, 'debit')), 2),
            ]);
            $voucher->entries()->createMany($entries);

            return $voucher;
        });

        return redirect()
            ->route('companies.vouchers.show', [$company, $voucher])
            ->with('success', 'Exchange '.($net >= 0 ? 'gain' : 'loss').' of '.inr(abs($net)).' posted.');
    }

    /**
     * Each foreign-currency ledger's balance in its currency and in rupees on $to.
     *
     * @return Collection<int, object{account: Account, foreign: float, rupees: float, book_rate: ?float}>
     */
    protected function ledgerPositions(Company $company, CarbonImmutable $to): Collection
    {
        return $company->accounts()->whereNotNull('currency_code')->orderBy('name')->get()
            ->map(function (Account $account) use ($to) {
                $entries = $account->entries()
                    ->join('vouchers', 'vouchers.id', '=', 'voucher_entries.voucher_id')
                    ->whereDate('vouchers.date', '<=', $to->toDateString())
                    ->get(['voucher_entries.debit', 'voucher_entries.credit', 'voucher_entries.foreign_amount']);

                // A foreign opening amount brings the opening balance into revaluation;
                // without one, the rupee opening is left as it is.
                $hasForeignOpening = $account->opening_foreign_amount !== null && (float) $account->opening_foreign_amount != 0;
                $foreign = round(($hasForeignOpening ? $account->signedOpeningForeign() : 0)
                    + $entries->sum(fn ($e) => ($e->debit > 0 ? 1 : -1) * (float) $e->foreign_amount), 2);
                $rupees = round(($hasForeignOpening ? $account->signedOpeningBalance() : 0)
                    + $entries->sum(fn ($e) => (float) $e->debit - (float) $e->credit), 2);

                return (object) [
                    'account' => $account,
                    'foreign' => $foreign,
                    'rupees' => $rupees,
                    'book_rate' => $foreign != 0 ? round($rupees / $foreign, 4) : null,
                ];
            })
            ->filter(fn ($row) => $row->foreign != 0 || $row->rupees != 0)
            ->values();
    }

    protected function asOn(Request $request, Company $company): CarbonImmutable
    {
        [, $fyEnd] = (new Books($company))->financialYear();

        return $request->filled('to') ? CarbonImmutable::parse($request->input('to')) : min(CarbonImmutable::today(), $fyEnd);
    }
}
