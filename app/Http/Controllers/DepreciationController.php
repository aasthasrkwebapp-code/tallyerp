<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\Company;
use App\Models\Voucher;
use App\Support\Books;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Fixed assets and yearly depreciation on the written-down value:
 * book value on the date × the ledger's rate, posted as one journal
 * (Dr Depreciation, Cr each asset) once per financial year.
 */
class DepreciationController extends Controller
{
    public function index(Request $request, Company $company)
    {
        $this->authorizeCompany($company);

        [, $fyEnd] = (new Books($company))->financialYear();
        $request->validate(['date' => ['nullable', 'date']]);
        $date = $request->filled('date') ? CarbonImmutable::parse($request->input('date')) : $fyEnd;

        return view('fixed-assets.index', [
            'company' => $company,
            'date' => $date,
            'assets' => self::assets($company, $date),
            'posted' => self::postedVoucher($company),
        ]);
    }

    public function update(Request $request, Company $company)
    {
        $this->authorizeCompany($company);

        $assetIds = self::assets($company, CarbonImmutable::today())->pluck('account.id')->all();
        $data = $request->validate([
            'rates' => ['array'],
            'rates.*' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ]);

        foreach ($data['rates'] ?? [] as $id => $rate) {
            abort_unless(in_array((int) $id, $assetIds, true), 404);
            $company->accounts()->whereKey($id)->first()->update(['depreciation_rate' => $rate === null || $rate === '' ? null : $rate]);
        }

        return back()->with('success', 'Depreciation rates saved.');
    }

    public function post(Request $request, Company $company)
    {
        $this->authorizeCompany($company);

        [$fyStart, $fyEnd] = (new Books($company))->financialYear();
        $data = $request->validate([
            'date' => ['required', 'date', 'after_or_equal:'.$fyStart->toDateString(), 'before_or_equal:'.$fyEnd->toDateString()],
        ]);

        if ($existing = self::postedVoucher($company)) {
            return back()->with('error', 'Depreciation for '.$company->financial_year.' is already posted (Journal #'.$existing->number.'). Delete that journal to post again.');
        }

        $date = CarbonImmutable::parse($data['date']);
        $lines = self::assets($company, $date)->filter(fn ($a) => $a->depreciation > 0);
        if ($lines->isEmpty()) {
            return back()->with('error', 'Nothing to depreciate: set a rate on assets that have a book value.');
        }

        $voucher = DB::transaction(function () use ($company, $date, $lines) {
            $total = round($lines->sum('depreciation'), 2);
            $expense = $company->systemLedger('DEPRECIATION', 'Depreciation', 'INDIRECT_EXPENSES');

            $voucher = $company->vouchers()->create([
                'type' => 'journal',
                'number' => Voucher::nextNumber($company, 'journal', $date),
                'date' => $date->toDateString(),
                'reference' => self::reference($company),
                'narration' => 'Depreciation for '.$company->financial_year.' (written-down value)',
                'amount' => $total,
            ]);
            $voucher->entries()->create(['account_id' => $expense->id, 'debit' => $total, 'credit' => 0]);
            foreach ($lines as $line) {
                $voucher->entries()->create(['account_id' => $line->account->id, 'debit' => 0, 'credit' => $line->depreciation]);
            }

            return $voucher;
        });

        return back()->with('success', 'Depreciation of ₹ '.inr($voucher->amount).' posted as Journal #'.$voucher->number.'.');
    }

    /**
     * Fixed-asset ledgers with book value on $date and the depreciation due.
     *
     * @return Collection<int, object{account: Account, value: float, rate: ?float, depreciation: float}>
     */
    public static function assets(Company $company, CarbonImmutable $date): Collection
    {
        $books = new Books($company);

        return $books->closingBalances($date)
            ->filter(fn (Account $a) => $books->isUnder($a, ['FIXED_ASSETS']))
            ->map(fn (Account $a) => (object) [
                'account' => $a,
                'value' => $a->balance,
                'rate' => $a->depreciation_rate !== null ? (float) $a->depreciation_rate : null,
                'depreciation' => $a->depreciation_rate !== null && $a->balance > 0 ? round($a->balance * (float) $a->depreciation_rate / 100, 2) : 0.0,
            ])
            ->values();
    }

    public static function reference(Company $company): string
    {
        return 'DEP/'.$company->financial_year;
    }

    public static function postedVoucher(Company $company): ?Voucher
    {
        return $company->vouchers()->where('type', 'journal')->where('reference', self::reference($company))->first();
    }
}
