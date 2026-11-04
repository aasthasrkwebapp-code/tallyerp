<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\Company;
use App\Models\Voucher;
use App\Support\Books;
use App\Support\Tds;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Book a supplier's bill with TDS deducted, and report TDS by section.
 */
class TdsController extends Controller
{
    public function index(Request $request, Company $company)
    {
        $this->authorizeCompany($company);

        $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date']]);
        [$fyStart, $fyEnd] = (new Books($company))->financialYear();
        $from = $request->filled('from') ? CarbonImmutable::parse($request->input('from')) : $fyStart;
        $to = $request->filled('to') ? CarbonImmutable::parse($request->input('to')) : $fyEnd;

        $deductions = $company->tdsDeductions()
            ->with(['voucher', 'party'])
            ->whereHas('voucher', fn ($q) => $q->whereBetween('date', [$from->toDateString(), $to->toDateString()]))
            ->get()
            ->sortBy(fn ($d) => $d->voucher->date->toDateString().'-'.$d->voucher_id)
            ->values();

        $sections = $deductions->groupBy(fn ($d) => Tds::SECTIONS[$d->section][0] ?? $d->section)
            ->map(fn ($items, $section) => (object) [
                'section' => $section,
                'count' => $items->count(),
                'base' => round($items->sum(fn ($d) => (float) $d->base_amount), 2),
                'tds' => round($items->sum(fn ($d) => (float) $d->tds_amount), 2),
            ])
            ->sortKeys()
            ->values();

        // What is still owed to the government: credit balance on each TDS Payable ledger.
        $payable = $company->accounts()->where('code', 'like', 'TDS_%')->orderBy('name')->get()
            ->map(fn (Account $ledger) => (object) [
                'ledger' => $ledger,
                'balance' => round(-($ledger->signedOpeningBalance() + (float) $ledger->entries()->sum('debit') - (float) $ledger->entries()->sum('credit')), 2),
            ]);

        return view('tds.index', compact('company', 'from', 'to', 'deductions', 'sections', 'payable'));
    }

    public function create(Company $company)
    {
        $this->authorizeCompany($company);

        $books = new Books($company);
        $accounts = $company->accounts()->where('is_active', true)->orderBy('name')->get();

        return view('tds.create', [
            'company' => $company,
            'parties' => $accounts->whereNotNull('tds_section')->values(),
            'expenses' => $accounts->filter(fn (Account $a) => in_array(
                $books->primaryGroup($a->account_group_id)->code,
                ['DIRECT_EXPENSES', 'INDIRECT_EXPENSES', 'PURCHASE_ACCOUNTS', 'FIXED_ASSETS'],
                true,
            ))->values(),
        ]);
    }

    public function store(Request $request, Company $company)
    {
        $this->authorizeCompany($company);

        [$start, $end] = (new Books($company))->financialYear();
        $inCompany = fn () => Rule::exists('chart_of_accounts', 'id')->where('company_id', $company->id);

        $data = $request->validate([
            'date' => ['required', 'date', 'after_or_equal:'.$start->toDateString(), 'before_or_equal:'.$end->toDateString()],
            'party_account_id' => ['required', $inCompany()],
            'expense_account_id' => ['required', $inCompany(), 'different:party_account_id'],
            'amount' => ['required', 'numeric', 'gt:0', 'max:9999999999999.99'],
            'reference' => ['nullable', 'string', 'max:255'],
            'narration' => ['nullable', 'string', 'max:2000'],
        ], [
            'date.after_or_equal' => 'The date must fall within financial year '.$company->financial_year.'.',
            'date.before_or_equal' => 'The date must fall within financial year '.$company->financial_year.'.',
        ]);

        $party = $company->accounts()->findOrFail($data['party_account_id']);
        if (! $party->tds_section || ! isset(Tds::SECTIONS[$party->tds_section])) {
            throw ValidationException::withMessages([
                'party_account_id' => 'Set a TDS section on '.$party->name.' first.',
            ]);
        }

        $amount = round((float) $data['amount'], 2);
        $rate = Tds::rate($party->tds_section, $party->pan);
        $tds = round($amount * $rate / 100);

        $voucher = DB::transaction(function () use ($company, $data, $party, $amount, $rate, $tds) {
            $voucher = $company->vouchers()->create([
                'type' => 'journal',
                'number' => Voucher::nextNumber($company, 'journal'),
                'date' => $data['date'],
                'reference' => $data['reference'] ?? null,
                'narration' => $data['narration']
                    ?? sprintf('TDS u/s %s @ %s%% on %s', Tds::SECTIONS[$party->tds_section][0], $rate, inr($amount)),
                'amount' => $amount,
                'party_account_id' => $party->id,
            ]);

            $entries = [
                ['account_id' => (int) $data['expense_account_id'], 'debit' => $amount, 'credit' => 0],
                ['account_id' => $party->id, 'debit' => 0, 'credit' => round($amount - $tds, 2)],
            ];
            if ($tds > 0) {
                $entries[] = ['account_id' => $company->tdsLedger($party->tds_section)->id, 'debit' => 0, 'credit' => $tds];
            }
            $voucher->entries()->createMany($entries);

            $company->tdsDeductions()->create([
                'voucher_id' => $voucher->id,
                'party_account_id' => $party->id,
                'section' => $party->tds_section,
                'base_amount' => $amount,
                'rate' => $rate,
                'tds_amount' => $tds,
            ]);

            return $voucher;
        });

        return redirect()
            ->route('companies.vouchers.show', [$company, $voucher])
            ->with('success', 'Bill booked with TDS of '.inr($tds).'.');
    }
}
