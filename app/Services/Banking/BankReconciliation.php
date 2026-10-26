<?php

namespace App\Services\Banking;

use App\Models\Account;
use App\Models\AuditLog;
use App\Models\BankStatementLine;
use App\Models\User;
use App\Models\Voucher;
use App\Models\VoucherEntry;
use App\Services\Reports\Outstanding;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Bank reconciliation of a bank ledger.
 *
 * Each posted entry on the ledger gets a bank date when it appears in the
 * bank. As on a date, entries not yet cleared by then explain the difference
 * between the balance in the books and the balance in the bank:
 *
 *   bank balance = book balance − uncleared deposits + uncleared withdrawals
 *
 * Bank statements can be imported (CSV) and matched to entries by amount,
 * direction and date; a match sets the entry's bank date to the statement date.
 */
class BankReconciliation
{
    /** Days either side of the statement date searched for a matching entry. */
    public const MATCH_WINDOW_DAYS = 10;

    public function __construct(protected Outstanding $balances) {}

    /** Posted entries of the ledger dated up to $to (and from $from), oldest first. */
    public function entries(Account $bank, ?string $from, string $to, bool $unreconciledOnly): Collection
    {
        return VoucherEntry::query()
            ->with(['voucher.type', 'voucher.entries.account'])
            ->where('account_id', $bank->id)
            ->whereHas('voucher', fn ($q) => $q->where('status', Voucher::POSTED)
                ->where('date', '<=', $to)
                ->when($from, fn ($w) => $w->where('date', '>=', $from)))
            ->when($unreconciledOnly, fn ($q) => $q->where(fn ($w) => $w->whereNull('bank_date')->orWhere('bank_date', '>', $to)))
            ->get()
            ->sortBy(fn ($e) => $e->voucher->date->toDateString().str_pad((string) $e->voucher_id, 10, '0', STR_PAD_LEFT))
            ->values();
    }

    /**
     * Book balance, uncleared amounts and the resulting bank balance as on $to,
     * in paise with debit (money in the bank) positive.
     */
    public function summary(Account $bank, string $to): array
    {
        $book = $this->balances->balances([$bank->id], $to)[$bank->id] ?? 0;

        $uncleared = DB::table('voucher_entries as e')
            ->join('vouchers as v', 'v.id', '=', 'e.voucher_id')
            ->where('e.account_id', $bank->id)
            ->where('v.status', Voucher::POSTED)
            ->where('v.date', '<=', $to)
            ->where(fn ($q) => $q->whereNull('e.bank_date')->orWhere('e.bank_date', '>', $to))
            ->selectRaw('COALESCE(SUM(ROUND(e.debit * 100)), 0) as dr, COALESCE(SUM(ROUND(e.credit * 100)), 0) as cr')
            ->first();

        $deposits = (int) $uncleared->dr;
        $withdrawals = (int) $uncleared->cr;

        return [
            'book' => $book,
            'uncleared_deposits' => $deposits,
            'uncleared_withdrawals' => $withdrawals,
            'bank' => $book - $deposits + $withdrawals,
        ];
    }

    /**
     * Set or clear bank dates. $dates maps voucher entry id => Y-m-d or null.
     *
     * @return int number of entries changed
     */
    public function setBankDates(Account $bank, array $dates, User $user): int
    {
        $errors = [];
        $entries = VoucherEntry::query()->with('voucher')->where('account_id', $bank->id)->whereIn('id', array_keys($dates))->get()->keyBy('id');
        $changes = [];

        foreach ($dates as $id => $date) {
            $entry = $entries->get((int) $id);
            if (! $entry || ! $entry->voucher->isPosted()) {
                $errors["dates.$id"] = 'An entry was not found on this bank ledger.';
                continue;
            }
            $date = $date ? Carbon::parse($date)->toDateString() : null;
            if ($date && $date < $entry->voucher->date->toDateString()) {
                $errors["dates.$id"] = "Voucher {$entry->voucher->displayNumber()}: the bank date cannot be before the voucher date.";
                continue;
            }
            if ($date !== $entry->bank_date?->toDateString()) {
                $changes[] = [$entry, $date];
            }
        }

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        DB::transaction(function () use ($changes, $user, $bank) {
            foreach ($changes as [$entry, $date]) {
                $old = $entry->bank_date?->toDateString();
                $entry->update(['bank_date' => $date]);
                if (! $date) {
                    BankStatementLine::query()->where('voucher_entry_id', $entry->id)->update(['voucher_entry_id' => null]);
                }
                AuditLog::record('bank_date', $entry->voucher, ['bank_date' => $old], ['bank_date' => $date],
                    "Bank date of {$entry->voucher->auditLabel()} on {$bank->name}");
            }
        });

        return count($changes);
    }

    /**
     * Import statement lines from CSV text. Columns are found by header name:
     * date; description / narration / particulars; reference / ref / cheque;
     * withdrawal / debit; deposit / credit (or one "amount" column, negative = withdrawal).
     *
     * @return int lines imported
     */
    public function import(Account $bank, string $csv, User $user): int
    {
        $rows = array_values(array_filter(array_map('str_getcsv', preg_split('/\r\n|\r|\n/', trim(preg_replace('/^\xEF\xBB\xBF/', '', $csv)))), fn ($r) => array_filter($r, fn ($c) => trim((string) $c) !== '') !== []));
        if (count($rows) < 2) {
            throw ValidationException::withMessages(['statement' => 'The file needs a header row and at least one line.']);
        }

        $header = array_map(fn ($h) => Str::lower(trim((string) $h)), array_shift($rows));
        $find = function (array $names) use ($header) {
            foreach ($header as $i => $h) {
                foreach ($names as $name) {
                    // Short names (dr, cr, ref) must match a whole word, so "description" is not "cr".
                    if ($h === $name || (strlen($name) > 3 ? str_contains($h, $name) : in_array($name, preg_split('/[^a-z]+/', $h), true))) {
                        return $i;
                    }
                }
            }

            return null;
        };

        $col = [
            'date' => $find(['date']),
            'description' => $find(['description', 'narration', 'particulars', 'remarks']),
            'reference' => $find(['reference', 'ref', 'cheque', 'chq', 'utr']),
            'withdrawal' => $find(['withdrawal', 'debit', 'dr']),
            'deposit' => $find(['deposit', 'credit', 'cr']),
            'amount' => $find(['amount']),
        ];
        if ($col['date'] === null || (($col['withdrawal'] === null || $col['deposit'] === null) && $col['amount'] === null)) {
            throw ValidationException::withMessages(['statement' => 'Columns not recognised. The header needs Date and either Withdrawal + Deposit (or Debit + Credit) or Amount.']);
        }

        $batch = (string) Str::uuid();
        $lines = [];
        $errors = [];
        foreach ($rows as $n => $row) {
            $lineNo = $n + 2;
            $date = $this->parseDate($row[$col['date']] ?? '');
            if (! $date) {
                $errors[] = "Row {$lineNo}: unreadable date \"".($row[$col['date']] ?? '').'".';
                continue;
            }
            try {
                if ($col['withdrawal'] !== null && $col['deposit'] !== null) {
                    $out = abs(Money::toPaise($row[$col['withdrawal']] ?? ''));
                    $in = abs(Money::toPaise($row[$col['deposit']] ?? ''));
                } else {
                    $amount = Money::toPaise($row[$col['amount']] ?? '');
                    [$out, $in] = $amount < 0 ? [-$amount, 0] : [0, $amount];
                }
            } catch (\InvalidArgumentException) {
                $errors[] = "Row {$lineNo}: unreadable amount.";
                continue;
            }
            if (($out === 0) === ($in === 0)) {
                $errors[] = "Row {$lineNo}: enter either a withdrawal or a deposit.";
                continue;
            }

            $lines[] = [
                'account_id' => $bank->id,
                'date' => $date,
                'description' => mb_substr(trim((string) ($row[$col['description']] ?? '')), 0, 500) ?: null,
                'reference' => $col['reference'] !== null ? (mb_substr(trim((string) ($row[$col['reference']] ?? '')), 0, 100) ?: null) : null,
                'withdrawal' => Money::fromPaise($out),
                'deposit' => Money::fromPaise($in),
                'import_batch' => $batch,
                'created_by' => $user->id,
            ];
        }

        if ($errors) {
            throw ValidationException::withMessages(['statement' => array_slice($errors, 0, 10)]);
        }
        if (count($lines) > 5000) {
            throw ValidationException::withMessages(['statement' => 'Import at most 5,000 lines at a time.']);
        }

        DB::transaction(function () use ($lines, $bank, $batch) {
            foreach ($lines as $line) {
                BankStatementLine::create($line);
            }
            AuditLog::record('bank_statement_imported', $bank, null, ['lines' => count($lines), 'batch' => $batch], 'Imported '.count($lines).' statement lines for '.$bank->name);
        });

        return count($lines);
    }

    /**
     * Match unmatched statement lines to uncleared entries with the same
     * amount and direction, dated within the window and not after the
     * statement date. A unique candidate (or the closest by date when the
     * reference also matches) is matched; ambiguous lines are left for manual matching.
     *
     * @return int lines matched
     */
    public function autoMatch(Account $bank, User $user): int
    {
        $matched = 0;
        $lines = BankStatementLine::query()->where('account_id', $bank->id)->whereNull('voucher_entry_id')->orderBy('date')->orderBy('id')->get();

        foreach ($lines as $line) {
            $candidates = $this->candidates($bank, $line);
            if ($line->reference) {
                $byRef = $candidates->filter(fn ($e) => $e->voucher->reference && Str::contains(Str::lower($line->reference.' '.$line->description), Str::lower($e->voucher->reference)));
                if ($byRef->count() === 1) {
                    $candidates = $byRef;
                }
            }
            if ($candidates->count() === 1) {
                $this->match($line, $candidates->first(), $user);
                $matched++;
            }
        }

        return $matched;
    }

    /** Uncleared posted entries that could explain this statement line. */
    public function candidates(Account $bank, BankStatementLine $line): Collection
    {
        $deposit = Money::toPaise($line->deposit) > 0;
        $amount = $deposit ? $line->deposit : $line->withdrawal;
        $from = $line->date->copy()->subDays(self::MATCH_WINDOW_DAYS)->toDateString();

        return VoucherEntry::query()->with('voucher')
            ->where('account_id', $bank->id)
            ->whereNull('bank_date')
            ->where($deposit ? 'debit' : 'credit', $amount)
            ->whereHas('voucher', fn ($q) => $q->where('status', Voucher::POSTED)->whereBetween('date', [$from, $line->date->toDateString()]))
            ->whereNotIn('id', BankStatementLine::query()->whereNotNull('voucher_entry_id')->select('voucher_entry_id'))
            ->get();
    }

    public function match(BankStatementLine $line, VoucherEntry $entry, User $user): void
    {
        $deposit = Money::toPaise($line->deposit) > 0;
        if ($entry->account_id !== $line->account_id || ! $entry->voucher->isPosted()
            || ($deposit ? $entry->debit : $entry->credit) !== ($deposit ? $line->deposit : $line->withdrawal)
            || $entry->voucher->date->gt($line->date)) {
            throw ValidationException::withMessages(['match' => 'That entry does not match this statement line (amount, side or date).']);
        }
        if (BankStatementLine::query()->where('voucher_entry_id', $entry->id)->whereKeyNot($line->id)->exists()) {
            throw ValidationException::withMessages(['match' => 'That entry is already matched to another statement line.']);
        }

        DB::transaction(function () use ($line, $entry, $user) {
            $line->update(['voucher_entry_id' => $entry->id]);
            $this->setBankDates($line->account, [$entry->id => $line->date->toDateString()], $user);
        });
    }

    public function unmatch(BankStatementLine $line, User $user): void
    {
        $entry = $line->entry;
        DB::transaction(function () use ($line, $entry, $user) {
            $line->update(['voucher_entry_id' => null]);
            if ($entry) {
                $this->setBankDates($line->account, [$entry->id => null], $user);
            }
        });
    }

    protected function parseDate(string $value): ?string
    {
        $value = trim($value);
        foreach (['Y-m-d', 'd-m-Y', 'd/m/Y', 'd.m.Y', 'd-M-Y', 'd M Y', 'd/m/y', 'd-m-y', 'd-M-y'] as $format) {
            try {
                $date = Carbon::createFromFormat('!'.$format, $value);
                if ($date && $date->format($format) === $value) {
                    return $date->toDateString();
                }
            } catch (\Throwable) {
            }
        }

        return null;
    }
}
