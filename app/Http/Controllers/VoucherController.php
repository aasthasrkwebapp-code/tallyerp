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

        $vouchers = $company->vouchers()
            ->with('entries.account')
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->string('type')))
            ->when($request->filled('from'), fn ($q) => $q->whereDate('date', '>=', $request->date('from')))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('date', '<=', $request->date('to')))
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        return view('vouchers.index', compact('company', 'vouchers'));
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

        return view('vouchers.create', compact('company', 'type', 'accounts'));
    }

    public function store(Request $request, Company $company)
    {
        $this->authorizeCompany($company);

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
        ], [
            'date.after_or_equal' => 'The date must fall within financial year '.$company->financial_year.'.',
            'date.before_or_equal' => 'The date must fall within financial year '.$company->financial_year.'.',
        ]);

        $entries = $this->normaliseEntries($data['entries']);
        $this->checkVoucherRules($company, $data['type'], $entries);

        $voucher = DB::transaction(function () use ($company, $data, $entries) {
            $voucher = $company->vouchers()->create([
                'type' => $data['type'],
                'number' => Voucher::nextNumber($company, $data['type']),
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
    protected function normaliseEntries(array $rows): array
    {
        $entries = [];

        foreach (array_values($rows) as $i => $row) {
            $debit = round((float) ($row['debit'] ?? 0), 2);
            $credit = round((float) ($row['credit'] ?? 0), 2);

            if ($debit > 0 && $credit > 0) {
                throw ValidationException::withMessages([
                    "entries.$i.debit" => 'Row '.($i + 1).' cannot have both a debit and a credit.',
                ]);
            }

            if ($debit == 0 && $credit == 0) {
                continue;
            }

            $entries[] = ['account_id' => (int) $row['account_id'], 'debit' => $debit, 'credit' => $credit];
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
