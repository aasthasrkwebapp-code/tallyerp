<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\Company;
use App\Models\VoucherEntry;
use App\Support\Books;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Bank reconciliation: mark when each bank entry cleared, and show the
 * gap between the books and the bank statement.
 */
class BankReconciliationController extends Controller
{
    protected const BANK_GROUPS = ['BANK_ACCOUNTS', 'BANK_OD'];

    public function show(Request $request, Company $company)
    {
        $this->authorizeCompany($company);

        $request->validate([
            'account' => ['nullable', 'integer'],
            'to' => ['nullable', 'date'],
            'show' => ['nullable', 'in:pending,all'],
        ]);

        $books = new Books($company);
        $banks = $this->bankAccounts($company, $books);
        $to = $request->filled('to') ? CarbonImmutable::parse($request->input('to')) : $books->financialYear()[1];
        $showAll = $request->input('show') === 'all';

        $account = null;
        $entries = collect();
        $summary = null;

        if ($request->filled('account')) {
            $account = $banks->firstWhere('id', $request->integer('account'));
            abort_unless($account, 404);

            $entries = $this->entriesQuery($account, $to)
                ->when(! $showAll, fn ($q) => $q->where(fn ($q) => $q
                    ->whereNull('voucher_entries.bank_date')
                    ->orWhere('voucher_entries.bank_date', '>', $to->toDateString())))
                ->with('voucher.entries.account')
                ->get();

            $summary = $this->summary($account, $to);
        }

        return view('banking.reconciliation', compact('company', 'banks', 'account', 'to', 'entries', 'summary', 'showAll'));
    }

    public function update(Request $request, Company $company)
    {
        $this->authorizeCompany($company);

        $data = $request->validate([
            'account_id' => ['required', 'integer'],
            'entries' => ['required', 'array'],
            'entries.*.bank_date' => ['nullable', 'date'],
            'entries.*.instrument_no' => ['nullable', 'string', 'max:50'],
        ]);

        $account = $this->bankAccounts($company, new Books($company))->firstWhere('id', (int) $data['account_id']);
        abort_unless($account, 404);

        DB::transaction(function () use ($account, $data) {
            $entries = $account->entries()->with('voucher')->whereKey(array_keys($data['entries']))->get()->keyBy('id');

            foreach ($data['entries'] as $id => $values) {
                $entry = $entries->get($id);
                abort_unless($entry, 404);

                $bankDate = $values['bank_date'] ?? null;
                if ($bankDate && CarbonImmutable::parse($bankDate)->lt($entry->voucher->date)) {
                    throw ValidationException::withMessages([
                        "entries.$id.bank_date" => 'Bank date for voucher #'.$entry->voucher->number.' cannot be before the voucher date.',
                    ]);
                }

                $entry->update([
                    'bank_date' => $bankDate,
                    'instrument_no' => $values['instrument_no'] ?? $entry->instrument_no,
                ]);
            }
        });

        return back()->with('success', 'Reconciliation saved.');
    }

    /**
     * @return array{books: float, not_in_bank_debits: float, not_in_bank_credits: float, bank: float}
     */
    protected function summary(Account $account, CarbonImmutable $to): array
    {
        $all = $this->entriesQuery($account, $to)->get();
        $books = round($account->signedOpeningBalance() + $all->sum(fn ($e) => (float) $e->debit - (float) $e->credit), 2);

        $pending = $all->filter(fn (VoucherEntry $e) => ! $e->bank_date || $e->bank_date->gt($to));
        $debits = round($pending->sum(fn ($e) => (float) $e->debit), 2);   // deposited, not yet credited by bank
        $credits = round($pending->sum(fn ($e) => (float) $e->credit), 2); // cheques issued, not yet presented

        return [
            'books' => $books,
            'not_in_bank_debits' => $debits,
            'not_in_bank_credits' => $credits,
            'bank' => round($books - $debits + $credits, 2),
        ];
    }

    protected function entriesQuery(Account $account, CarbonImmutable $to)
    {
        return $account->entries()
            ->select('voucher_entries.*')
            ->join('vouchers', 'vouchers.id', '=', 'voucher_entries.voucher_id')
            ->whereDate('vouchers.date', '<=', $to->toDateString())
            ->orderBy('vouchers.date')
            ->orderBy('vouchers.id');
    }

    protected function bankAccounts(Company $company, Books $books)
    {
        $bankGroupIds = $company->accountGroups()->whereIn('code', self::BANK_GROUPS)->pluck('id')->all();

        return $company->accounts()->orderBy('name')->get()->filter(function (Account $a) use ($books, $bankGroupIds) {
            // Bank ledgers sit under Bank Accounts / Bank OD or a sub-group of them.
            for ($groupId = $a->account_group_id; $groupId; $groupId = $books->groupParent($groupId)) {
                if (in_array($groupId, $bankGroupIds)) {
                    return true;
                }
            }

            return false;
        })->values();
    }
}
