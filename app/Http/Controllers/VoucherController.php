<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\Voucher;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class VoucherController extends Controller
{
    /** Group codes whose ledgers count as cash or bank. */
    protected const CASH_BANK_GROUPS = ['CASH_IN_HAND', 'BANK_ACCOUNTS', 'BANK_OD'];

    public function index(Request $request, Company $company)
    {
        $this->authorizeCompany($company);

        // Month-wise register of one voucher type (Sales Register, Purchase Register…).
        if ($request->query('view') === 'monthly' && array_key_exists($request->query('type'), Voucher::TYPES)) {
            return view('vouchers.index', ['company' => $company, 'vouchers' => null, 'months' => $this->monthly($company, $request->query('type'))]);
        }

        $vouchers = $company->vouchers()
            ->with('entries.account')
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->string('type')))
            ->when($request->filled('from'), fn ($q) => $q->whereDate('date', '>=', $request->date('from')))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('date', '<=', $request->date('to')))
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        return view('vouchers.index', ['company' => $company, 'vouchers' => $vouchers, 'months' => null]);
    }

    /**
     * Count, taxable value, GST and total of one voucher type for each month of the year.
     */
    protected function monthly(Company $company, string $type): \Illuminate\Support\Collection
    {
        [$fyStart, $fyEnd] = (new \App\Support\Books($company))->financialYear();

        $vouchers = $company->vouchers()->where('type', $type)
            ->whereDate('date', '>=', $fyStart->toDateString())->whereDate('date', '<=', $fyEnd->toDateString())
            ->with('gstLines')->get();

        $months = collect();
        for ($m = $fyStart->startOfMonth(); $m->lte($fyEnd); $m = $m->addMonth()) {
            $in = $vouchers->filter(fn (Voucher $v) => $v->date->format('Y-m') === $m->format('Y-m'));
            $months->push((object) [
                'month' => $m,
                'count' => $in->count(),
                // Vouchers without GST lines count in full as taxable value.
                'taxable' => round($in->sum(fn (Voucher $v) => $v->gstLines->isEmpty() ? (float) $v->amount : (float) $v->gstLines->sum('taxable')), 2),
                'tax' => round($in->sum(fn (Voucher $v) => (float) $v->gstLines->sum(fn ($l) => $l->cgst + $l->sgst + $l->igst)), 2),
                'total' => round($in->sum(fn (Voucher $v) => (float) $v->amount), 2),
            ]);
        }

        return $months;
    }

    public function create(Request $request, Company $company)
    {
        $this->authorizeCompany($company);

        $type = array_key_exists($request->query('type'), Voucher::TYPES)
            ? $request->query('type')
            : 'journal';

        $accounts = $company->accounts()
            ->where('is_active', true)
            ->with('group')
            ->orderBy('name')
            ->get();

        return view('vouchers.create', [
            'company' => $company,
            'type' => $type,
            'accounts' => $accounts,
            'voucher' => new Voucher,
            'costCentres' => $company->costCentres()->orderBy('name')->get(),
        ]);
    }

    public function edit(Company $company, Voucher $voucher)
    {
        $this->authorizeCompany($company);

        if ($redirect = $this->refuseInvoiceEdit($company, $voucher)) {
            return $redirect;
        }

        $voucher->load('entries');

        // Keep inactive ledgers already used on this voucher selectable.
        $accounts = $company->accounts()
            ->where(fn ($q) => $q->where('is_active', true)->orWhereIn('id', $voucher->entries->pluck('account_id')))
            ->with('group')
            ->orderBy('name')
            ->get();

        return view('vouchers.create', [
            'company' => $company,
            'type' => $voucher->type,
            'accounts' => $accounts,
            'voucher' => $voucher,
            'costCentres' => $company->costCentres()->orderBy('name')->get(),
        ]);
    }

    public function update(Request $request, Company $company, Voucher $voucher)
    {
        $this->authorizeCompany($company);

        if ($redirect = $this->refuseInvoiceEdit($company, $voucher)) {
            return $redirect;
        }

        // The type (and so the number series) stays fixed once saved.
        $request->merge(['type' => $voucher->type]);
        [$data, $entries] = $this->validatedVoucher($request, $company);

        DB::transaction(function () use ($voucher, $data, $entries) {
            // Keep reconciliation details on lines that didn't change.
            $previous = $voucher->entries()->get()->groupBy(fn ($e) => $e->account_id.'|'.(float) $e->debit.'|'.(float) $e->credit);

            // Moving to another financial year takes the next number in that year's series.
            if (Voucher::fyStartYear($data['date']) !== (int) $voucher->fy_start_year) {
                $voucher->number = Voucher::nextNumber($voucher->company, $voucher->type, $data['date']);
            }

            $voucher->update([
                'date' => $data['date'],
                'reference' => $data['reference'] ?? null,
                'narration' => $data['narration'] ?? null,
                'amount' => array_sum(array_column($entries, 'debit')),
            ]);

            $voucher->entries()->delete();
            foreach ($entries as $entry) {
                $old = $previous->get($entry['account_id'].'|'.$entry['debit'].'|'.$entry['credit'])?->shift();
                $voucher->entries()->create($entry + [
                    'instrument_no' => $old?->instrument_no,
                    'bank_date' => $old?->bank_date,
                ]);
            }
        });

        return redirect()
            ->route('companies.vouchers.show', [$company, $voucher])
            ->with('success', $voucher->typeLabel().' voucher #'.$voucher->number.' updated.');
    }

    public function store(Request $request, Company $company)
    {
        $this->authorizeCompany($company);

        [$data, $entries] = $this->validatedVoucher($request, $company);

        $voucher = DB::transaction(function () use ($company, $data, $entries) {
            $voucher = $company->vouchers()->create([
                'type' => $data['type'],
                'number' => Voucher::nextNumber($company, $data['type'], $data['date']),
                'date' => $data['date'],
                'reference' => $data['reference'] ?? null,
                'narration' => $data['narration'] ?? null,
                'amount' => array_sum(array_column($entries, 'debit')),
            ]);

            $voucher->entries()->createMany($entries);

            return $voucher;
        });

        return redirect()
            ->route('companies.vouchers.show', [$company, $voucher])
            ->with('success', $voucher->typeLabel().' voucher #'.$voucher->number.' saved.');
    }

    /**
     * GST invoices (tax lines, stock) and TDS bills (deduction records) are
     * re-entered rather than edited.
     */
    protected function refuseInvoiceEdit(Company $company, Voucher $voucher)
    {
        $kind = match (true) {
            $voucher->gstLines()->exists() => 'GST invoices',
            $voucher->tdsDeductions()->exists() => 'Bills with TDS',
            // Revaluation lines change rupees only (foreign amount 0).
            $voucher->entries()->whereNotNull('currency_code')->where('foreign_amount', 0)->exists() => 'Forex revaluations',
            default => null,
        };

        if (! $kind) {
            return null;
        }

        return redirect()
            ->route('companies.vouchers.show', [$company, $voucher])
            ->with('error', $kind.' cannot be edited. Delete it and enter it again.');
    }

    /**
     * @return array{0: array, 1: array}
     */
    protected function validatedVoucher(Request $request, Company $company): array
    {
        [$start, $end] = $this->financialYear($company);

        $data = $request->validate([
            'type' => ['required', Rule::in(array_keys(Voucher::TYPES))],
            'date' => ['required', 'date', 'after_or_equal:'.$start->toDateString(), 'before_or_equal:'.$end->toDateString()],
            'reference' => ['nullable', 'string', 'max:255'],
            'narration' => ['nullable', 'string', 'max:2000'],
            'entries' => ['required', 'array', 'min:2'],
            'entries.*.account_id' => [
                'required',
                Rule::exists('chart_of_accounts', 'id')->where('company_id', $company->id),
            ],
            'entries.*.debit' => ['nullable', 'numeric', 'min:0', 'max:9999999999999.99'],
            'entries.*.credit' => ['nullable', 'numeric', 'min:0', 'max:9999999999999.99'],
            'entries.*.foreign_amount' => ['nullable', 'numeric', 'gt:0', 'max:9999999999999.99'],
            'entries.*.exchange_rate' => ['nullable', 'numeric', 'gt:0', 'max:99999999.999999'],
            'entries.*.side' => ['nullable', 'in:dr,cr'],
            'entries.*.cost_centre_id' => ['nullable', Rule::exists('cost_centres', 'id')->where('company_id', $company->id)],
        ], [
            'date.after_or_equal' => 'The date must fall within financial year '.$company->financial_year.'.',
            'date.before_or_equal' => 'The date must fall within financial year '.$company->financial_year.'.',
        ]);

        $currencies = $company->accounts()->whereNotNull('currency_code')->pluck('currency_code', 'id')->all();
        $entries = $this->normaliseEntries($data['entries'], $currencies);
        $this->checkVoucherRules($company, $data['type'], $entries);

        return [$data, $entries];
    }

    public function show(Company $company, Voucher $voucher)
    {
        $this->authorizeCompany($company);

        $voucher->load('entries.account.group');

        return view('vouchers.show', compact('company', 'voucher'));
    }

    public function destroy(Company $company, Voucher $voucher)
    {
        $this->authorizeCompany($company);

        $voucher->delete();

        return redirect()
            ->route('companies.vouchers.index', $company)
            ->with('success', 'Voucher deleted.');
    }

    /**
     * Drop blank rows and check each remaining row is either a debit or a credit.
     *
     * @return array<int, array{account_id: int, debit: float, credit: float}>
     */
    protected function normaliseEntries(array $rows, array $currencies = []): array
    {
        $entries = [];

        foreach (array_values($rows) as $i => $row) {
            $debit = round((float) ($row['debit'] ?? 0), 2);
            $credit = round((float) ($row['credit'] ?? 0), 2);

            // Foreign-currency ledger: the rupee amount is foreign amount × rate.
            $currency = $currencies[(int) ($row['account_id'] ?? 0)] ?? null;
            $foreign = null;
            if ($currency) {
                $amount = (float) ($row['foreign_amount'] ?? 0);
                $rate = (float) ($row['exchange_rate'] ?? 0);

                if ($amount <= 0 && $debit == 0 && $credit == 0) {
                    continue; // blank row
                }
                if ($amount <= 0 || $rate <= 0) {
                    throw ValidationException::withMessages([
                        "entries.$i.foreign_amount" => 'Row '.($i + 1).': enter the '.$currency.' amount and exchange rate.',
                    ]);
                }

                $rupees = round($amount * $rate, 2);
                $isCredit = ($row['side'] ?? null) === 'cr' || (! isset($row['side']) && $credit > 0 && $debit == 0);
                [$debit, $credit] = $isCredit ? [0.0, $rupees] : [$rupees, 0.0];
                $foreign = ['currency_code' => $currency, 'foreign_amount' => round($amount, 2), 'exchange_rate' => round($rate, 6)];
            }

            if ($debit > 0 && $credit > 0) {
                throw ValidationException::withMessages([
                    "entries.$i.debit" => 'Row '.($i + 1).' cannot have both a debit and a credit.',
                ]);
            }

            if ($debit == 0 && $credit == 0) {
                continue;
            }

            $entries[] = [
                'account_id' => (int) $row['account_id'],
                'cost_centre_id' => ! empty($row['cost_centre_id']) ? (int) $row['cost_centre_id'] : null,
                'debit' => $debit,
                'credit' => $credit,
                'currency_code' => $foreign['currency_code'] ?? null,
                'foreign_amount' => $foreign['foreign_amount'] ?? null,
                'exchange_rate' => $foreign['exchange_rate'] ?? null,
            ];
        }

        $debits = round(array_sum(array_column($entries, 'debit')), 2);
        $credits = round(array_sum(array_column($entries, 'credit')), 2);

        if ($debits == 0 || $credits == 0) {
            throw ValidationException::withMessages([
                'entries' => 'A voucher needs at least one debit and one credit.',
            ]);
        }

        if ($debits !== $credits) {
            throw ValidationException::withMessages([
                'entries' => sprintf('Debit total (%.2f) must equal credit total (%.2f).', $debits, $credits),
            ]);
        }

        return $entries;
    }

    /**
     * Tally-style rules on which ledgers each voucher type may use.
     */
    protected function checkVoucherRules(Company $company, string $type, array $entries): void
    {
        $cashBank = $this->cashBankAccountIds($company);
        $isCashBank = fn (array $e) => in_array($e['account_id'], $cashBank, true);

        $error = match ($type) {
            'contra' => collect($entries)->every($isCashBank)
                ? null : 'Contra vouchers can only use cash and bank ledgers.',
            'payment' => collect($entries)->contains(fn ($e) => $isCashBank($e) && $e['credit'] > 0)
                ? null : 'A payment must credit a cash or bank ledger.',
            'receipt' => collect($entries)->contains(fn ($e) => $isCashBank($e) && $e['debit'] > 0)
                ? null : 'A receipt must debit a cash or bank ledger.',
            default => null,
        };

        if ($error) {
            throw ValidationException::withMessages(['entries' => $error]);
        }
    }

    /**
     * Ids of ledgers under Cash-in-Hand, Bank Accounts or Bank OD, including their sub-groups.
     *
     * @return array<int, int>
     */
    protected function cashBankAccountIds(Company $company): array
    {
        $groups = $company->accountGroups()->get(['id', 'parent_id', 'code']);
        $ids = $groups->whereIn('code', self::CASH_BANK_GROUPS)->pluck('id')->all();

        // Walk down to user-created sub-groups.
        do {
            $children = $groups->whereIn('parent_id', $ids)->whereNotIn('id', $ids)->pluck('id')->all();
            $ids = array_merge($ids, $children);
        } while ($children);

        return $company->accounts()->whereIn('account_group_id', $ids)->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * Start and end dates of the company's financial year, e.g. "2026-27" → 1 Apr 2026 to 31 Mar 2027.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    protected function financialYear(Company $company): array
    {
        $startYear = (int) substr($company->financial_year, 0, 4);
        $start = CarbonImmutable::create($startYear, 4, 1);

        return [$start, $start->addYear()->subDay()];
    }
}
