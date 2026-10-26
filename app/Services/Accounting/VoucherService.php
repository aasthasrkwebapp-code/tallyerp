<?php

namespace App\Services\Accounting;

use App\Models\Account;
use App\Models\AuditLog;
use App\Models\BillAllocation;
use App\Models\Company;
use App\Models\CostCentre;
use App\Models\FinancialYear;
use App\Models\User;
use App\Models\Voucher;
use App\Models\VoucherNumberSequence;
use App\Models\VoucherRevision;
use App\Models\VoucherType;
use App\Support\CurrentCompany;
use App\Support\Money;
use Illuminate\Database\QueryException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The double-entry posting engine.
 *
 * Every write goes through here so the accounting rules hold everywhere:
 * posted vouchers balance, header and lines are saved atomically, numbers are
 * unique and gap-controlled, locked periods are respected, posted vouchers
 * are never deleted (only cancelled or reversed) and every alteration keeps a
 * revision snapshot.
 *
 * Input format for create()/update():
 *   voucher_type_id, date, voucher_number (manual numbering only), reference,
 *   reference_date, narration, entries: [
 *     account_id, side (Dr|Cr), amount, cost_centre_id, narration,
 *     bills: [bill_type, bill_ref, amount, due_date]
 *   ]
 */
class VoucherService
{
    public function __construct(
        protected CurrentCompany $current,
        protected BillOutstanding $bills,
    ) {}

    /** Status a save request ends in, given the intent and the user's rights. */
    public function resolveStatus(User $user, string $intent): string
    {
        if ($intent !== 'post') {
            return Voucher::DRAFT;
        }

        $company = $this->company();

        if ($company->setting('require_voucher_approval') && ! $user->hasPermission('vouchers.approve', $company)) {
            return Voucher::PENDING;
        }

        if ($user->hasPermission('vouchers.post', $company)) {
            return Voucher::POSTED;
        }

        return $company->setting('require_voucher_approval') ? Voucher::PENDING : Voucher::DRAFT;
    }

    public function create(array $data, User $user, string $status = Voucher::POSTED, ?Model $source = null): Voucher
    {
        return $this->transaction(function () use ($data, $user, $status, $source) {
            [$type, $year, $lines] = $this->validate($data, null, $status);

            $voucher = new Voucher([
                'voucher_type_id' => $type->id,
                'financial_year_id' => $year->id,
                'date' => $data['date'],
                'reference' => $data['reference'] ?? null,
                'reference_date' => $data['reference_date'] ?? null,
                'narration' => $data['narration'] ?? $type->default_narration,
                'status' => $status,
                'created_by' => $user->id,
                'updated_by' => $user->id,
            ]);

            if ($source) {
                $voucher->source()->associate($source);
            }

            $this->fillTotals($voucher, $lines);

            if ($status === Voucher::POSTED) {
                $this->assignNumber($voucher, $type, $year, $data['voucher_number'] ?? null);
                $voucher->posted_at = now();
                if ($this->company()->setting('require_voucher_approval')) {
                    $voucher->approved_by = $user->id;
                    $voucher->approved_at = now();
                }
            }

            $voucher->save();
            $this->writeLines($voucher, $lines);

            return $voucher->load('entries.billAllocations');
        });
    }

    public function update(Voucher $voucher, array $data, User $user, string $status, ?string $reason = null): Voucher
    {
        if ($voucher->isCancelled()) {
            throw ValidationException::withMessages(['voucher' => 'A cancelled voucher cannot be altered.']);
        }

        return $this->transaction(function () use ($voucher, $data, $user, $status, $reason) {
            $voucher = Voucher::query()->lockForUpdate()->findOrFail($voucher->id);
            $wasPosted = $voucher->isPosted();

            if ($wasPosted) {
                // A posted voucher stays posted; its old date must not be in a locked period either.
                $status = Voucher::POSTED;
                $this->assertDateOpen($voucher->financialYear, $voucher->date);
            }

            [$type, $year, $lines] = $this->validate($data, $voucher, $status);

            if ($wasPosted) {
                if ($type->base_type !== $voucher->type->base_type || $year->id !== $voucher->financial_year_id) {
                    throw ValidationException::withMessages(['date' => 'A posted voucher cannot move to another voucher class or financial year. Cancel it and enter a new one.']);
                }
                $this->assertAlterationKeepsSettlements($voucher, $lines);
                $this->snapshot($voucher, $user, $reason);
                $voucher->revision++;
            }

            $voucher->fill([
                'voucher_type_id' => $type->id,
                'financial_year_id' => $year->id,
                'date' => $data['date'],
                'reference' => $data['reference'] ?? null,
                'reference_date' => $data['reference_date'] ?? null,
                'narration' => $data['narration'] ?? null,
                'status' => $status,
                'updated_by' => $user->id,
            ]);
            $this->fillTotals($voucher, $lines);

            if ($status === Voucher::POSTED && ! $wasPosted) {
                $this->assignNumber($voucher, $type, $year, $data['voucher_number'] ?? null);
                $voucher->posted_at = now();
                if ($this->company()->setting('require_voucher_approval')) {
                    $voucher->approved_by = $user->id;
                    $voucher->approved_at = now();
                }
            } elseif ($wasPosted && ! $type->isAutoNumbered() && ! empty($data['voucher_number'])) {
                $voucher->voucher_number = $data['voucher_number'];
            }

            $voucher->save();

            $voucher->entries()->each(fn ($e) => $e->delete());
            $this->writeLines($voucher, $lines);

            return $voucher->load('entries.billAllocations');
        });
    }

    /** Post (or approve) a draft or pending voucher using its saved lines. */
    public function post(Voucher $voucher, User $user): Voucher
    {
        if (! in_array($voucher->status, [Voucher::DRAFT, Voucher::PENDING], true)) {
            throw ValidationException::withMessages(['voucher' => 'Only draft or pending vouchers can be posted.']);
        }

        $status = $voucher->isPending() || $this->company()->setting('require_voucher_approval')
            ? ($user->hasPermission('vouchers.approve', $this->company()) ? Voucher::POSTED : Voucher::PENDING)
            : Voucher::POSTED;

        $data = $this->toInput($voucher);

        return $this->update($voucher, $data, $user, $status);
    }

    public function cancel(Voucher $voucher, User $user, string $reason): Voucher
    {
        if (! $voucher->isPosted()) {
            throw ValidationException::withMessages(['voucher' => 'Only posted vouchers can be cancelled.']);
        }

        return $this->transaction(function () use ($voucher, $user, $reason) {
            $voucher = Voucher::query()->lockForUpdate()->findOrFail($voucher->id);
            $this->assertDateOpen($voucher->financialYear, $voucher->date);
            if ($voucher->reversal()->where('status', Voucher::POSTED)->exists()) {
                throw ValidationException::withMessages(['voucher' => 'This voucher has been reversed. Cancel the reversal first.']);
            }
            $this->assertNotSettled($voucher);

            $voucher->update([
                'status' => Voucher::CANCELLED,
                'cancelled_by' => $user->id,
                'cancelled_at' => now(),
                'cancellation_reason' => $reason,
            ]);

            return $voucher;
        });
    }

    /** Create a posted voucher with every line on the opposite side. */
    public function reverse(Voucher $voucher, User $user, string $date, ?string $narration = null): Voucher
    {
        if (! $voucher->isPosted()) {
            throw ValidationException::withMessages(['voucher' => 'Only posted vouchers can be reversed.']);
        }
        if ($voucher->reversal()->exists()) {
            throw ValidationException::withMessages(['voucher' => 'This voucher has already been reversed.']);
        }

        $type = VoucherType::forBase('journal') ?? $voucher->type;
        $input = $this->toInput($voucher);
        $input['voucher_type_id'] = $type->id;
        $input['date'] = $date;
        $input['voucher_number'] = null;
        $input['narration'] = $narration ?: 'Reversal of '.$voucher->type->name.' '.$voucher->voucher_number;
        $input['allow_cash_in_journal'] = true;
        $input['skip_bill_checks'] = true;
        $input['entries'] = array_map(function ($line) {
            $line['side'] = $line['side'] === 'Dr' ? 'Cr' : 'Dr';
            // Reversal settles each original bill reference.
            $line['bills'] = array_map(fn ($b) => array_merge($b, ['bill_type' => $b['bill_ref'] ? 'agst_ref' : 'on_account']), $line['bills'] ?? []);

            return $line;
        }, $input['entries']);

        return $this->transaction(function () use ($input, $user, $voucher) {
            $reversal = $this->create($input, $user, Voucher::POSTED);
            $reversal->update(['reversal_of_id' => $voucher->id]);

            return $reversal;
        });
    }

    public function delete(Voucher $voucher): void
    {
        if ($voucher->isPosted() || $voucher->isCancelled()) {
            throw ValidationException::withMessages(['voucher' => 'Posted vouchers cannot be deleted. Cancel or reverse them instead.']);
        }

        DB::transaction(function () use ($voucher) {
            AuditLog::record('deleted', $voucher, $this->toInput($voucher), null, 'Deleted '.$voucher->auditLabel());
            $voucher->entries()->each(fn ($e) => $e->delete());
            $voucher->delete();
        });
    }

    /** Current voucher as create()/update() input. */
    public function toInput(Voucher $voucher): array
    {
        $voucher->loadMissing('entries.billAllocations');

        return [
            'voucher_type_id' => $voucher->voucher_type_id,
            'date' => $voucher->date->toDateString(),
            'voucher_number' => $voucher->voucher_number,
            'reference' => $voucher->reference,
            'reference_date' => $voucher->reference_date?->toDateString(),
            'narration' => $voucher->narration,
            'entries' => $voucher->entries->map(fn ($e) => [
                'account_id' => $e->account_id,
                'side' => $e->side(),
                'amount' => Money::fromPaise($e->amountPaise()),
                'cost_centre_id' => $e->cost_centre_id,
                'narration' => $e->narration,
                'bills' => $e->billAllocations->map(fn ($b) => [
                    'bill_type' => $b->bill_type,
                    'bill_ref' => $b->bill_ref,
                    'amount' => $b->amount,
                    'due_date' => $b->due_date?->toDateString(),
                ])->all(),
            ])->all(),
        ];
    }

    // ------------------------------------------------------------------
    // Validation
    // ------------------------------------------------------------------

    /** @return array{0: VoucherType, 1: FinancialYear, 2: array} */
    protected function validate(array $data, ?Voucher $existing, string $status): array
    {
        $company = $this->company();
        $errors = [];

        $type = VoucherType::query()->whereKey($data['voucher_type_id'] ?? 0)->first();
        if (! $type || (! $type->is_active && ! $existing)) {
            throw ValidationException::withMessages(['voucher_type_id' => 'Select a valid voucher type.']);
        }

        try {
            $date = Carbon::parse($data['date'] ?? null)->startOfDay();
        } catch (\Throwable) {
            throw ValidationException::withMessages(['date' => 'Enter a valid date.']);
        }
        if (empty($data['date'])) {
            throw ValidationException::withMessages(['date' => 'Enter the voucher date.']);
        }

        $year = $company->financialYearFor($date);
        if (! $year) {
            throw ValidationException::withMessages(['date' => 'The date is outside every financial year of this company. Open the financial year first.']);
        }
        if ($company->books_begin_date && $date->lt($company->books_begin_date)) {
            $errors['date'] = 'The date is before the books beginning date ('.$company->books_begin_date->format('d M Y').').';
        }
        if (! $company->setting('allow_future_dated_vouchers') && $date->gt(now()->endOfDay())) {
            $errors['date'] = 'Future-dated vouchers are not allowed (change this in company preferences).';
        }
        if ($status !== Voucher::DRAFT && $year->isDateLocked($date)) {
            $errors['date'] = 'This date falls in a locked financial year or period.';
        }

        if (! $type->isAutoNumbered() && $status === Voucher::POSTED) {
            $number = trim((string) ($data['voucher_number'] ?? ''));
            if ($number === '') {
                $errors['voucher_number'] = 'Enter the voucher number (this voucher type uses manual numbering).';
            } elseif (Voucher::query()->where('voucher_type_id', $type->id)->where('financial_year_id', $year->id)
                ->where('voucher_number', $number)->when($existing, fn ($q) => $q->whereKeyNot($existing->id))->exists()) {
                $errors['voucher_number'] = "Voucher number {$number} is already used.";
            }
        }

        $lines = $this->validateLines(
            $data['entries'] ?? [], $type, $status, $existing, $errors,
            (bool) ($data['allow_cash_in_journal'] ?? false),
            (bool) ($data['skip_bill_checks'] ?? false),
        );

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        return [$type, $year, $lines];
    }

    protected function validateLines(array $entries, VoucherType $type, string $status, ?Voucher $existing, array &$errors, bool $allowCashInJournal, bool $skipBillChecks = false): array
    {
        $company = $this->company();
        $strict = $status !== Voucher::DRAFT;
        $lines = [];

        $entries = array_values(array_filter($entries, fn ($e) => ! empty($e['account_id']) || ($e['amount'] ?? '') !== ''));
        $accounts = Account::query()->with('group')->whereIn('id', array_column($entries, 'account_id'))->get()->keyBy('id');

        foreach ($entries as $i => $entry) {
            $account = $accounts->get((int) ($entry['account_id'] ?? 0));
            if (! $account) {
                $errors["entries.$i.account_id"] = 'Line '.($i + 1).': select a ledger.';
                continue;
            }
            if (! $account->is_active && $strict) {
                $errors["entries.$i.account_id"] = "Line ".($i + 1).": ledger {$account->name} is inactive.";
            }

            $side = $entry['side'] ?? null;
            if (! in_array($side, ['Dr', 'Cr'], true)) {
                $errors["entries.$i.side"] = 'Line '.($i + 1).': choose Dr or Cr.';
                continue;
            }

            try {
                $paise = Money::toPaise($entry['amount'] ?? '');
            } catch (\InvalidArgumentException) {
                $errors["entries.$i.amount"] = 'Line '.($i + 1).': enter a valid amount.';
                continue;
            }
            if (preg_match('/\.\d{3,}$/', (string) ($entry['amount'] ?? ''))) {
                $errors["entries.$i.amount"] = 'Line '.($i + 1).': amounts can have at most 2 decimal places.';
            }
            if ($paise <= 0) {
                $errors["entries.$i.amount"] = 'Line '.($i + 1).': the amount must be greater than zero.';
                continue;
            }
            if ($paise > 99999999999999999) {
                $errors["entries.$i.amount"] = 'Line '.($i + 1).': the amount is too large.';
                continue;
            }

            $costCentreId = $entry['cost_centre_id'] ?? null;
            if ($costCentreId) {
                $centre = CostCentre::query()->find($costCentreId);
                if (! $centre) {
                    $errors["entries.$i.cost_centre_id"] = 'Line '.($i + 1).': invalid cost centre.';
                } elseif (! in_array($account->group->nature, ['income', 'expense'], true)) {
                    $errors["entries.$i.cost_centre_id"] = 'Line '.($i + 1).': cost centres apply to income and expense ledgers only.';
                }
            }

            $lines[] = [
                'account' => $account,
                'side' => $side,
                'paise' => $paise,
                'cost_centre_id' => $costCentreId ?: null,
                'narration' => isset($entry['narration']) ? mb_substr((string) $entry['narration'], 0, 500) : null,
                'bills' => $entry['bills'] ?? [],
                'index' => $i,
            ];
        }

        if (! $strict) {
            if ($lines === [] && ! $errors) {
                $errors['entries'] = 'Add at least one line.';
            }

            // Drafts keep whatever well-formed bill rows were entered; they are checked on posting.
            foreach ($lines as $k => $line) {
                $lines[$k]['bills'] = $line['account']->maintain_bill_wise ? $this->looseBills($line['bills']) : [];
            }

            return $lines;
        }

        if (count($lines) < 2 && ! $errors) {
            $errors['entries'] = 'A voucher needs at least one debit and one credit line.';
        }

        $debits = array_sum(array_map(fn ($l) => $l['side'] === 'Dr' ? $l['paise'] : 0, $lines));
        $credits = array_sum(array_map(fn ($l) => $l['side'] === 'Cr' ? $l['paise'] : 0, $lines));

        if ($debits !== $credits) {
            $errors['entries'] = 'Total debits ('.Money::format($debits, paiseInput: true).') must equal total credits ('
                .Money::format($credits, paiseInput: true).'). Difference: '.Money::format(abs($debits - $credits), paiseInput: true).'.';
        }

        $this->checkTypeRules($type, $lines, $errors, $allowCashInJournal);

        if (! $errors) {
            foreach ($lines as $k => $line) {
                $lines[$k]['bills'] = $skipBillChecks
                    ? ($line['account']->maintain_bill_wise ? $this->looseBills($line['bills']) : [])
                    : $this->validateBills($line, $existing, $errors);
            }
        }

        return $lines;
    }

    /** Well-formed bill rows without settlement checks (drafts and system reversals). */
    protected function looseBills(array $bills): array
    {
        $out = [];
        foreach ($bills as $bill) {
            try {
                $paise = Money::toPaise($bill['amount'] ?? '');
            } catch (\InvalidArgumentException) {
                continue;
            }
            if ($paise <= 0 || ! array_key_exists($bill['bill_type'] ?? '', BillAllocation::TYPES)) {
                continue;
            }
            $ref = trim((string) ($bill['bill_ref'] ?? ''));
            $out[] = [
                'bill_type' => $bill['bill_type'],
                'bill_ref' => $bill['bill_type'] === 'on_account' || $ref === '' ? null : $ref,
                'paise' => $paise,
                'due_date' => $bill['due_date'] ?? null,
            ];
        }

        return $out;
    }

    protected function checkTypeRules(VoucherType $type, array $lines, array &$errors, bool $allowCashInJournal): void
    {
        $has = fn (string $side, array $types) => (bool) array_filter($lines, fn ($l) => $l['side'] === $side && in_array($l['account']->account_type, $types, true));
        $cashBank = ['cash', 'bank'];

        switch ($type->base_type) {
            case 'contra':
                if (array_filter($lines, fn ($l) => ! $l['account']->isCashOrBank())) {
                    $errors['entries'] = 'Contra vouchers can only use cash and bank ledgers.';
                }
                break;
            case 'payment':
                if (! $has('Cr', $cashBank)) {
                    $errors['entries'] = 'A payment voucher must credit a cash or bank ledger.';
                }
                break;
            case 'receipt':
                if (! $has('Dr', $cashBank)) {
                    $errors['entries'] = 'A receipt voucher must debit a cash or bank ledger.';
                }
                break;
            case 'journal':
                if (! $allowCashInJournal && array_filter($lines, fn ($l) => $l['account']->isCashOrBank())) {
                    $errors['entries'] = 'Journal vouchers cannot use cash or bank ledgers. Use a payment, receipt or contra voucher.';
                }
                break;
            case 'sales':
                if (! $has('Cr', ['sales'])) {
                    $errors['entries'] = 'A sales voucher must credit a sales ledger.';
                }
                break;
            case 'purchase':
                if (! $has('Dr', ['purchase'])) {
                    $errors['entries'] = 'A purchase voucher must debit a purchase ledger.';
                }
                break;
            case 'credit_note':
                if (! $has('Cr', ['party', 'cash', 'bank'])) {
                    $errors['entries'] = 'A credit note must credit a party, cash or bank ledger.';
                }
                break;
            case 'debit_note':
                if (! $has('Dr', ['party', 'cash', 'bank'])) {
                    $errors['entries'] = 'A debit note must debit a party, cash or bank ledger.';
                }
                break;
        }
    }

    /** Validate and normalise bill-wise allocations for one line. */
    protected function validateBills(array $line, ?Voucher $existing, array &$errors): array
    {
        $account = $line['account'];
        $i = $line['index'];

        if (! $account->maintain_bill_wise) {
            return [];
        }

        $bills = array_values(array_filter($line['bills'], fn ($b) => ($b['amount'] ?? '') !== '' || ! empty($b['bill_ref'])));

        if ($bills === []) {
            return [['bill_type' => 'on_account', 'bill_ref' => null, 'paise' => $line['paise'], 'due_date' => null]];
        }

        $out = [];
        $total = 0;
        $seen = [];

        foreach ($bills as $j => $bill) {
            $label = 'Line '.($i + 1).', bill '.($j + 1);
            $billType = $bill['bill_type'] ?? '';
            $ref = trim((string) ($bill['bill_ref'] ?? ''));

            if (! array_key_exists($billType, BillAllocation::TYPES)) {
                $errors["entries.$i.bills.$j.bill_type"] = "{$label}: choose the type of reference.";
                continue;
            }
            try {
                $paise = Money::toPaise($bill['amount'] ?? '');
            } catch (\InvalidArgumentException) {
                $paise = 0;
            }
            if ($paise <= 0) {
                $errors["entries.$i.bills.$j.amount"] = "{$label}: enter an amount greater than zero.";
                continue;
            }
            if ($billType !== 'on_account' && $ref === '') {
                $errors["entries.$i.bills.$j.bill_ref"] = "{$label}: enter the bill reference.";
                continue;
            }
            $key = mb_strtolower($ref);
            if ($ref !== '' && isset($seen[$key])) {
                $errors["entries.$i.bills.$j.bill_ref"] = "{$label}: reference {$ref} is repeated.";
                continue;
            }
            $seen[$key] = true;

            if ($ref === Voucher::OWN_NUMBER) {
                if ($billType !== 'new_ref') {
                    $errors["entries.$i.bills.$j.bill_ref"] = "{$label}: only a new reference can use this voucher's number.";
                    continue;
                }
                // Uniqueness is checked once the number is assigned (writeLines).
                $out[] = ['bill_type' => $billType, 'bill_ref' => $ref, 'paise' => $paise, 'due_date' => $bill['due_date'] ?? null];
                $total += $paise;
                continue;
            }

            $existingBill = $ref !== '' ? $this->bills->find($account->id, $ref, $existing?->id) : null;

            if (in_array($billType, ['new_ref', 'advance'], true) && $existingBill && in_array($existingBill->bill_type, ['new_ref', 'advance'], true)) {
                $errors["entries.$i.bills.$j.bill_ref"] = "{$label}: bill {$ref} already exists for {$account->name}. Use Agst Ref to settle it.";
                continue;
            }

            if ($billType === 'agst_ref') {
                if (! $existingBill || $existingBill->pending === 0) {
                    $errors["entries.$i.bills.$j.bill_ref"] = "{$label}: there is no pending bill {$ref} for {$account->name}.";
                    continue;
                }
                if ($existingBill->side === $line['side']) {
                    $errors["entries.$i.bills.$j.bill_ref"] = "{$label}: bill {$ref} is a {$existingBill->side} bill and cannot be settled by another {$line['side']} entry.";
                    continue;
                }
                if ($paise > $existingBill->pending) {
                    $errors["entries.$i.bills.$j.amount"] = "{$label}: only ".Money::format($existingBill->pending, paiseInput: true)." is pending on bill {$ref}.";
                    continue;
                }
            }

            $out[] = [
                'bill_type' => $billType,
                'bill_ref' => $billType === 'on_account' ? null : $ref,
                'paise' => $paise,
                'due_date' => $bill['due_date'] ?? null,
            ];
            $total += $paise;
        }

        if (! isset($errors["entries.$i.bills"]) && $total !== $line['paise'] && $out !== []) {
            $errors["entries.$i.bills"] = 'Line '.($i + 1).': bill allocations ('.Money::format($total, paiseInput: true)
                .') must add up to the line amount ('.Money::format($line['paise'], paiseInput: true).').';
        }

        return $out;
    }

    // ------------------------------------------------------------------
    // Persistence helpers
    // ------------------------------------------------------------------

    protected function fillTotals(Voucher $voucher, array $lines): void
    {
        $voucher->total = Money::fromPaise(array_sum(array_map(fn ($l) => $l['side'] === 'Dr' ? $l['paise'] : 0, $lines)));
        $party = collect($lines)->first(fn ($l) => $l['account']->account_type === 'party');
        $voucher->party_account_id = $party ? $party['account']->id : null;
    }

    protected function writeLines(Voucher $voucher, array $lines): void
    {
        foreach (array_values($lines) as $n => $line) {
            $entry = $voucher->entries()->create([
                'company_id' => $voucher->company_id,
                'account_id' => $line['account']->id,
                'line_no' => $n + 1,
                'debit' => $line['side'] === 'Dr' ? Money::fromPaise($line['paise']) : '0.00',
                'credit' => $line['side'] === 'Cr' ? Money::fromPaise($line['paise']) : '0.00',
                'cost_centre_id' => $line['cost_centre_id'],
                'narration' => $line['narration'],
            ]);

            foreach ($line['bills'] as $bill) {
                if (! isset($bill['paise'])) {
                    continue; // drafts keep raw input only after validation on posting
                }
                $ref = $bill['bill_ref'];
                if ($ref === Voucher::OWN_NUMBER && $voucher->isPosted()) {
                    $ref = $voucher->voucher_number;
                    if ($this->bills->find($line['account']->id, $ref, $voucher->id)) {
                        throw ValidationException::withMessages(['entries' => "Bill {$ref} already exists for {$line['account']->name}. Change the voucher numbering (e.g. a yearly prefix) or enter the bill reference manually."]);
                    }
                }
                $entry->billAllocations()->create([
                    'company_id' => $voucher->company_id,
                    'account_id' => $line['account']->id,
                    'bill_type' => $bill['bill_type'],
                    'bill_ref' => $ref,
                    'amount' => Money::fromPaise($bill['paise']),
                    'side' => $line['side'],
                    'due_date' => $bill['due_date'] ?: null,
                ]);
            }
        }
    }

    protected function assignNumber(Voucher $voucher, VoucherType $type, FinancialYear $year, ?string $manual): void
    {
        if (! $type->isAutoNumbered()) {
            $voucher->voucher_number = trim((string) $manual);
            $voucher->sequence_number = null;

            return;
        }

        $yearId = $type->restart_yearly ? $year->id : null;

        $sequence = VoucherNumberSequence::query()
            ->where('voucher_type_id', $type->id)
            ->where('financial_year_id', $yearId)
            ->lockForUpdate()
            ->first();

        if (! $sequence) {
            $max = Voucher::query()->where('voucher_type_id', $type->id)
                ->when($yearId, fn ($q) => $q->where('financial_year_id', $yearId))
                ->max('sequence_number');

            $sequence = VoucherNumberSequence::create([
                'voucher_type_id' => $type->id,
                'financial_year_id' => $yearId,
                'next_number' => max((int) $max + 1, $type->starting_number),
            ]);
            $sequence = VoucherNumberSequence::query()->lockForUpdate()->find($sequence->id);
        }

        $number = $sequence->next_number;
        $sequence->update(['next_number' => $number + 1]);

        $voucher->sequence_number = $number;
        $voucher->voucher_number = $type->formatNumber($number);
    }

    protected function snapshot(Voucher $voucher, User $user, ?string $reason): void
    {
        VoucherRevision::create([
            'company_id' => $voucher->company_id,
            'voucher_id' => $voucher->id,
            'revision' => $voucher->revision + 1,
            'snapshot' => array_merge($this->toInput($voucher), [
                'status' => $voucher->status,
                'total' => $voucher->total,
                'updated_by' => $voucher->updated_by,
                'updated_at' => $voucher->updated_at?->toDateTimeString(),
            ]),
            'reason' => $reason,
            'changed_by' => $user->id,
        ]);
    }

    protected function assertDateOpen(FinancialYear $year, $date): void
    {
        if ($year->isDateLocked($date)) {
            throw ValidationException::withMessages(['date' => 'The voucher is in a locked financial year or period and cannot be changed.']);
        }
    }

    /** A voucher that opened bills cannot be cancelled while other vouchers settle those bills. */
    protected function assertNotSettled(Voucher $voucher): void
    {
        $refs = BillAllocation::query()
            ->whereIn('voucher_entry_id', $voucher->entries()->pluck('id'))
            ->whereIn('bill_type', ['new_ref', 'advance'])
            ->get(['account_id', 'bill_ref']);

        foreach ($refs as $ref) {
            $settled = BillAllocation::query()
                ->join('voucher_entries as e', 'e.id', '=', 'bill_allocations.voucher_entry_id')
                ->join('vouchers as v', 'v.id', '=', 'e.voucher_id')
                ->where('v.status', Voucher::POSTED)
                ->where('v.id', '!=', $voucher->id)
                ->where('bill_allocations.account_id', $ref->account_id)
                ->where('bill_allocations.bill_ref', $ref->bill_ref)
                ->where('bill_allocations.bill_type', 'agst_ref')
                ->exists();

            if ($settled) {
                throw ValidationException::withMessages(['voucher' => "Bill {$ref->bill_ref} of this voucher has been settled by other vouchers. Cancel those first."]);
            }
        }
    }

    /**
     * When a posted voucher that opened bills is altered, every bill already
     * settled by other vouchers must still exist with at least the settled amount.
     */
    protected function assertAlterationKeepsSettlements(Voucher $voucher, array $lines): void
    {
        $opened = BillAllocation::query()
            ->whereIn('voucher_entry_id', $voucher->entries()->pluck('id'))
            ->whereIn('bill_type', ['new_ref', 'advance'])
            ->get();

        foreach ($opened as $bill) {
            $settled = (int) round(BillAllocation::query()
                ->join('voucher_entries as e', 'e.id', '=', 'bill_allocations.voucher_entry_id')
                ->join('vouchers as v', 'v.id', '=', 'e.voucher_id')
                ->where('v.status', Voucher::POSTED)
                ->where('v.id', '!=', $voucher->id)
                ->where('bill_allocations.account_id', $bill->account_id)
                ->where('bill_allocations.bill_ref', $bill->bill_ref)
                ->where('bill_allocations.bill_type', 'agst_ref')
                ->get(['bill_allocations.amount'])
                ->sum(fn ($r) => Money::toPaise($r->amount)));

            if ($settled === 0) {
                continue;
            }

            $kept = 0;
            foreach ($lines as $line) {
                if ($line['account']->id !== $bill->account_id || $line['side'] !== $bill->side) {
                    continue;
                }
                foreach ($line['bills'] as $b) {
                    if (in_array($b['bill_type'], ['new_ref', 'advance'], true) && mb_strtolower((string) $b['bill_ref']) === mb_strtolower($bill->bill_ref)) {
                        $kept += $b['paise'];
                    }
                }
            }

            if ($kept < $settled) {
                throw ValidationException::withMessages(['entries' => "Bill {$bill->bill_ref} has ".Money::format($settled, paiseInput: true)
                    .' already settled by other vouchers; the altered voucher must keep at least that amount on the bill.']);
            }
        }
    }

    protected function company(): Company
    {
        return $this->current->get() ?? throw new \LogicException('No active company.');
    }

    /** Run in a transaction, retrying once if a concurrent insert took the same number. */
    protected function transaction(callable $callback)
    {
        $attempts = 0;
        retry:
        try {
            return DB::transaction($callback);
        } catch (QueryException $e) {
            if (++$attempts < 3 && str_contains($e->getMessage(), 'vouchers_number_unique')) {
                goto retry;
            }
            throw $e;
        }
    }
}
