<?php

namespace App\Services\Accounting;

use App\Models\Account;
use App\Models\OpeningBill;
use App\Models\Voucher;
use App\Support\CurrentCompany;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Opening bills of bill-wise parties.
 *
 * A party's opening balance can be split into the bills (Dr for a customer's invoice, Cr for
 * a supplier's bill or an advance) that were pending when the books began. They count in
 * outstanding and ageing like bills of vouchers and are settled Agst Ref by receipts and
 * payments. Together they may not come to more than the opening balance; any rest is
 * shown on account. A bill already settled cannot be removed or cut below what was settled.
 */
class OpeningBills
{
    public function __construct(protected CurrentCompany $current) {}

    /** Paise settled against each opening bill of a ledger, keyed by lower-case reference. */
    public function settled(Account $account): array
    {
        return DB::table('bill_allocations as b')
            ->join('voucher_entries as e', 'e.id', '=', 'b.voucher_entry_id')
            ->join('vouchers as v', 'v.id', '=', 'e.voucher_id')
            ->where('v.status', Voucher::POSTED)
            ->where('b.account_id', $account->id)
            ->where('b.bill_type', 'agst_ref')
            ->whereIn(DB::raw('LOWER(b.bill_ref)'), $account->openingBills()->pluck('bill_ref')->map(fn ($r) => mb_strtolower($r))->all())
            ->get(['b.bill_ref', 'b.amount'])
            ->groupBy(fn ($r) => mb_strtolower($r->bill_ref))
            ->map(fn (Collection $rows) => $rows->sum(fn ($r) => Money::toPaise($r->amount)))
            ->all();
    }

    /** Signed total (Dr positive) of a ledger's opening bills, in paise. */
    public function net(Account $account): int
    {
        return $account->openingBills()->get(['amount', 'side'])->sum(fn ($b) => ($b->side === 'Dr' ? 1 : -1) * Money::toPaise($b->amount));
    }

    /**
     * Replace a ledger's opening bills.
     *
     * @param  array<int, array{id?: ?int, bill_ref?: ?string, date?: ?string, due_date?: ?string, amount?: ?string, side?: ?string}>  $rows
     */
    public function save(Account $account, array $rows): Collection
    {
        if (! $account->maintain_bill_wise || $account->account_type !== 'party') {
            throw ValidationException::withMessages(['bills' => 'Opening bills can be entered only for party ledgers kept bill-by-bill.']);
        }
        $begin = $this->current->get()->books_begin_date;
        $beginDate = $begin ? Carbon::parse($begin)->toDateString() : null;
        $existing = $account->openingBills()->get()->keyBy('id');
        $settled = $this->settled($account);
        $errors = [];
        $clean = [];
        $seen = [];
        $claimed = collect($rows)->pluck('id')->filter(fn ($id) => $existing->has((int) $id))->map(fn ($id) => (int) $id)->all();
        $used = [];

        foreach (array_values($rows) as $i => $row) {
            $ref = trim((string) ($row['bill_ref'] ?? ''));
            $amount = str_replace(',', '', trim((string) ($row['amount'] ?? '')));
            if ($ref === '' && $amount === '' && trim((string) ($row['date'] ?? '')) === '') {
                continue; // blank row
            }
            $label = 'Row '.($i + 1).($ref !== '' ? " ({$ref})" : '');
            $key = mb_strtolower($ref);
            $id = isset($row['id']) && $existing->has((int) $row['id']) ? (int) $row['id'] : null;

            if ($ref === '' || mb_strlen($ref) > 100) {
                $errors["bills.$i.bill_ref"] = "{$label}: enter the bill reference (up to 100 characters).";
            } elseif (isset($seen[$key])) {
                $errors["bills.$i.bill_ref"] = "{$label}: the reference is entered twice.";
            }
            $seen[$key] = true;

            $date = $this->date($row['date'] ?? null);
            $due = $this->date($row['due_date'] ?? null);
            if (! $date) {
                $errors["bills.$i.date"] = "{$label}: enter the bill date.";
            } elseif ($beginDate && $date > $beginDate) {
                $errors["bills.$i.date"] = "{$label}: an opening bill is dated on or before the books begin (".Carbon::parse($beginDate)->format('d-m-Y').'). Enter later bills as vouchers.';
            }
            if (trim((string) ($row['due_date'] ?? '')) !== '' && ! $due) {
                $errors["bills.$i.due_date"] = "{$label}: enter a valid due date.";
            } elseif ($due && $date && $due < $date) {
                $errors["bills.$i.due_date"] = "{$label}: the due date cannot be before the bill date.";
            }
            if (! preg_match('/^\d{1,16}(\.\d{1,2})?$/', $amount) || Money::toPaise($amount) <= 0) {
                $errors["bills.$i.amount"] = "{$label}: enter an amount above zero with at most 2 decimal places.";
                $paise = 0;
            } else {
                $paise = Money::toPaise($amount);
            }
            $side = in_array($row['side'] ?? '', ['Dr', 'Cr'], true) ? $row['side'] : $account->opening_balance_type;

            if ($ref !== '' && ! $this->refFree($account, $ref)) {
                $errors["bills.$i.bill_ref"] = "{$label}: a voucher already opened bill {$ref} for {$account->name}.";
            }
            // A row without an id that repeats an existing reference updates that bill.
            $previous = $id ? $existing[$id] : $existing->first(fn ($b) => mb_strtolower($b->bill_ref) === $key && ! in_array($b->id, $claimed, true) && ! isset($used[$b->id]));
            if ($previous) {
                $used[$previous->id] = true;
            }
            if ($previous && ($done = $settled[mb_strtolower($previous->bill_ref)] ?? 0) > 0) {
                if (mb_strtolower($previous->bill_ref) !== $key || $previous->side !== $side) {
                    $errors["bills.$i.bill_ref"] = "{$label}: ".Money::format($done, paiseInput: true)." of bill {$previous->bill_ref} is settled, so its reference and Dr / Cr cannot change.";
                } elseif ($paise > 0 && $paise < $done) {
                    $errors["bills.$i.amount"] = "{$label}: ".Money::format($done, paiseInput: true).' of this bill is already settled.';
                }
            }

            $clean[] = ['id' => $previous?->id, 'bill_ref' => $ref, 'date' => $date, 'due_date' => $due, 'paise' => $paise, 'side' => $side];
        }

        $kept = collect($clean)->pluck('id')->filter()->all();
        foreach ($existing->except($kept) as $gone) {
            if (($done = $settled[mb_strtolower($gone->bill_ref)] ?? 0) > 0) {
                $errors['bills'] = "Bill {$gone->bill_ref} cannot be removed: ".Money::format($done, paiseInput: true).' of it is settled. Cancel those settlements first.';
            }
        }

        $net = collect($clean)->sum(fn ($b) => ($b['side'] === 'Dr' ? 1 : -1) * $b['paise']);
        $opening = $account->openingPaise();
        if (! $errors && $clean !== [] && $this->exceeds($net, $opening)) {
            $errors['bills'] = 'The opening bills come to '.$this->signed($net).' but the opening balance is '.$this->signed($opening)
                .'. They may come to the opening balance or less (the rest is kept on account).';
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        return DB::transaction(function () use ($account, $existing, $kept, $clean) {
            foreach ($existing->except($kept) as $gone) {
                $gone->delete();
            }
            // Free references first so renames between rows don't hit the unique index.
            foreach ($clean as $b) {
                if ($b['id'] && mb_strtolower($existing[$b['id']]->bill_ref) !== mb_strtolower($b['bill_ref'])) {
                    $existing[$b['id']]->updateQuietly(['bill_ref' => '~'.$b['id'].'~'.$existing[$b['id']]->bill_ref]);
                }
            }
            foreach ($clean as $b) {
                $attributes = ['bill_ref' => $b['bill_ref'], 'date' => $b['date'], 'due_date' => $b['due_date'], 'amount' => Money::fromPaise($b['paise']), 'side' => $b['side']];
                if ($b['id']) {
                    $bill = $existing[$b['id']];
                    $bill->fill($attributes)->save();
                } else {
                    $account->openingBills()->create($attributes);
                }
            }

            return $account->openingBills()->orderBy('date')->orderBy('id')->get();
        });
    }

    /**
     * Problems with changing a ledger's bill-wise setting or opening balance while it has opening bills.
     *
     * @return array<string, string> field => message
     */
    public function ledgerChangeErrors(Account $ledger, bool $billWise, string $amount, string $type): array
    {
        if (! $ledger->exists || ! $ledger->openingBills()->exists()) {
            return [];
        }
        if (! $billWise) {
            return ['maintain_bill_wise' => 'This ledger has opening bills. Remove them before it stops being kept bill-by-bill.'];
        }
        $net = $this->net($ledger);
        $paise = Money::toPaise(Money::normalize(str_replace(',', '', $amount) ?: '0'));
        $opening = $type === 'Cr' ? -$paise : $paise;
        if ($this->exceeds($net, $opening)) {
            return ['opening_balance' => 'The opening bills of this ledger come to '.$this->signed($net).'. The opening balance must cover them; change the opening bills first.'];
        }

        return [];
    }

    /** Bills netting to $net do not fit in an opening balance of $opening (same direction, no larger). */
    protected function exceeds(int $net, int $opening): bool
    {
        return $opening === 0 ? $net !== 0 : ($net * $opening < 0 || abs($net) > abs($opening));
    }

    /** A reference no voucher has opened for this ledger. */
    protected function refFree(Account $account, string $ref): bool
    {
        return ! DB::table('bill_allocations')->where('account_id', $account->id)->whereIn('bill_type', ['new_ref', 'advance'])
            ->whereRaw('LOWER(bill_ref) = ?', [mb_strtolower($ref)])->exists();
    }

    protected function date(mixed $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        try {
            $d = Carbon::createFromFormat('!Y-m-d', $value);
        } catch (\Throwable) {
            return null;
        }

        return $d && $d->format('Y-m-d') === $value ? $value : null;
    }

    protected function signed(int $paise): string
    {
        return Money::format(abs($paise), paiseInput: true).($paise === 0 ? '' : ($paise > 0 ? ' Dr' : ' Cr'));
    }
}
