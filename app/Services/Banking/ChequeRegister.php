<?php

namespace App\Services\Banking;

use App\Models\Account;
use App\Models\ChequeBook;
use App\Models\SpoiltCheque;
use App\Models\Voucher;
use App\Models\VoucherEntry;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Cheques and other instruments on bank vouchers.
 *
 * The instrument is entered on the voucher; the bank line decides the bank
 * and whether the cheque was issued (bank credited) or received (bank
 * debited). An issued cheque must come from that bank's cheque books, once.
 * Cleared or not is read from the bank line's reconciliation date.
 */
class ChequeRegister
{
    /** Cheques older than this many days are stale (RBI: valid for three months). */
    public const STALE_DAYS = 90;

    public const STATUSES = [
        'pending' => 'Not yet cleared',
        'cleared' => 'Cleared',
        'stale' => 'Stale (over 3 months)',
        'post_dated' => 'Post-dated',
        'cancelled' => 'Cancelled',
        'reversed' => 'Reversed (e.g. dishonoured)',
        'draft' => 'Draft / pending approval',
        'spoilt' => 'Spoilt leaf',
    ];

    /**
     * Instrument columns for a voucher being saved, and any errors.
     *
     * @param  array  $lines  validated voucher lines (each with 'account' and 'side')
     * @return array{0: array<string, mixed>, 1: array<string, string>}
     */
    public function resolve(array $data, array $lines, ?Voucher $existing, string $voucherDate): array
    {
        $empty = ['instrument_type' => null, 'instrument_number' => null, 'instrument_date' => null, 'payee_name' => null, 'instrument_account_id' => null, 'instrument_direction' => null];
        $type = $data['instrument_type'] ?? null;
        if (! $type) {
            return [$empty, []];
        }
        if (! array_key_exists($type, Voucher::INSTRUMENTS)) {
            return [$empty, ['instrument_type' => 'Choose a valid instrument.']];
        }
        $label = Voucher::INSTRUMENTS[$type];

        $banks = collect($lines)->filter(fn ($l) => $l['account']->account_type === 'bank');
        $paying = $banks->where('side', 'Cr');
        $bankLine = $paying->first() ?? $banks->first();
        if (! $bankLine) {
            return [$empty, ['instrument_type' => "A {$label} needs a bank ledger line."]];
        }
        if ($paying->pluck('account.id')->unique()->count() > 1) {
            return [$empty, ['instrument_type' => "Only one bank can pay by {$label} in a voucher."]];
        }
        $bank = $bankLine['account'];
        $direction = $paying->isNotEmpty() ? 'issued' : 'received';

        $number = preg_replace('/\s+/', '', (string) ($data['instrument_number'] ?? ''));
        $errors = [];
        if ($type === 'cheque') {
            if ($number === '') {
                $errors['instrument_number'] = 'Enter the cheque number.';
            } elseif (! ctype_digit($number) || strlen($number) > 12) {
                $errors['instrument_number'] = 'A cheque number has digits only (up to 12).';
            } else {
                $number = str_pad(ltrim($number, '0') ?: '0', 6, '0', STR_PAD_LEFT);
            }
        } elseif ($type === 'dd' && $number === '') {
            $errors['instrument_number'] = 'Enter the demand draft number.';
        }
        if (mb_strlen($number) > 30) {
            $errors['instrument_number'] = 'The number can be up to 30 characters.';
        }
        $date = $data['instrument_date'] ?? null;
        if ($date && strtotime($date) === false) {
            $errors['instrument_date'] = 'Enter a valid instrument date.';
        }

        if (! $errors && $type === 'cheque' && $direction === 'issued') {
            $errors += $this->checkIssued($bank, $number, $existing);
        }

        $payee = trim((string) ($data['payee_name'] ?? ''));
        if ($payee === '' && $direction === 'issued') {
            $payee = (string) collect($lines)->first(fn ($l) => $l['side'] === 'Dr' && $l['account']->account_type !== 'bank')['account']?->name;
        }

        return [[
            'instrument_type' => $type,
            'instrument_number' => $number !== '' ? $number : null,
            'instrument_date' => $date ?: ($type === 'cheque' || $type === 'dd' ? $voucherDate : null),
            'payee_name' => $payee !== '' ? mb_substr($payee, 0, 150) : null,
            'instrument_account_id' => $bank->id,
            'instrument_direction' => $direction,
        ], $errors];
    }

    /** @return array<string, string> */
    protected function checkIssued(Account $bank, string $number, ?Voucher $existing): array
    {
        $books = ChequeBook::query()->where('account_id', $bank->id)->get();
        if ($books->isNotEmpty()) {
            $book = $books->first(fn (ChequeBook $b) => $b->contains($number));
            if (! $book) {
                return ['instrument_number' => "Cheque {$number} is not in any cheque book of {$bank->name}."];
            }
            if ($book->spoilt()->get()->contains(fn ($s) => (int) $s->number === (int) $number)) {
                return ['instrument_number' => "Cheque {$number} was marked spoilt."];
            }
        }
        $used = Voucher::query()->where('instrument_account_id', $bank->id)->where('instrument_type', 'cheque')
            ->where('instrument_direction', 'issued')->where('instrument_number', $number)
            ->where('status', '!=', Voucher::CANCELLED)
            ->when($existing, fn ($q) => $q->whereKeyNot($existing->id))->first();

        return $used ? ['instrument_number' => "Cheque {$number} of {$bank->name} is already used on voucher {$used->displayNumber()}."] : [];
    }

    /**
     * Register rows: vouchers with an instrument, plus spoilt leaves.
     *
     * @param  array{bank?: ?int, from?: ?string, to?: ?string, status?: ?string, direction?: ?string, type?: ?string, q?: ?string}  $filters
     */
    public function rows(array $filters): Collection
    {
        $today = now()->toDateString();
        $vouchers = Voucher::query()->whereNotNull('instrument_type')
            ->with(['type', 'instrumentAccount', 'entries', 'reversal'])
            ->when($filters['bank'] ?? null, fn ($q, $v) => $q->where('instrument_account_id', $v))
            ->when($filters['direction'] ?? null, fn ($q, $v) => $q->where('instrument_direction', $v))
            ->when($filters['type'] ?? null, fn ($q, $v) => $q->where('instrument_type', $v))
            ->when($filters['from'] ?? null, fn ($q, $v) => $q->where(fn ($w) => $w->where('instrument_date', '>=', $v)->orWhere(fn ($n) => $n->whereNull('instrument_date')->where('date', '>=', $v))))
            ->when($filters['to'] ?? null, fn ($q, $v) => $q->where(fn ($w) => $w->where('instrument_date', '<=', $v)->orWhere(fn ($n) => $n->whereNull('instrument_date')->where('date', '<=', $v))))
            ->when($filters['q'] ?? null, fn ($q, $v) => $q->where(fn ($w) => $w->where('instrument_number', 'like', "%{$v}%")->orWhere('payee_name', 'like', "%{$v}%")->orWhere('voucher_number', 'like', "%{$v}%")))
            ->get();

        $rows = $vouchers->map(function (Voucher $v) use ($today) {
            $entry = $v->entries->first(fn (VoucherEntry $e) => $e->account_id === $v->instrument_account_id
                && ($v->instrument_direction === 'issued' ? (float) $e->credit > 0 : (float) $e->debit > 0));
            $date = ($v->instrument_date ?? $v->date)->toDateString();
            $status = match (true) {
                $v->isCancelled() => 'cancelled',
                $v->reversal !== null => 'reversed',
                ! $v->isPosted() => 'draft',
                $entry?->bank_date !== null => 'cleared',
                $date > $today => 'post_dated',
                in_array($v->instrument_type, ['cheque', 'dd'], true) && Carbon::parse($date)->diffInDays($today) > self::STALE_DAYS => 'stale',
                default => 'pending',
            };

            return (object) [
                'kind' => 'voucher', 'voucher' => $v, 'date' => $date, 'number' => $v->instrument_number,
                'type' => $v->instrument_type, 'direction' => $v->instrument_direction, 'bank' => $v->instrumentAccount,
                'party' => $v->payee_name ?? $this->counterparty($v), 'amount' => $entry ? (float) ($v->instrument_direction === 'issued' ? $entry->credit : $entry->debit) : (float) $v->total,
                'status' => $status, 'bank_date' => $entry?->bank_date, 'reason' => $v->cancellation_reason,
                'days' => $status === 'pending' || $status === 'stale' ? (int) Carbon::parse($date)->diffInDays($today) : null,
            ];
        });

        if (($filters['direction'] ?? 'issued') === 'issued' && in_array($filters['type'] ?? 'cheque', ['cheque', null, ''], true)) {
            $spoilt = SpoiltCheque::query()->with('book.account')
                ->when($filters['bank'] ?? null, fn ($q, $v) => $q->whereHas('book', fn ($b) => $b->where('account_id', $v)))
                ->when($filters['from'] ?? null, fn ($q, $v) => $q->where('date', '>=', $v))
                ->when($filters['to'] ?? null, fn ($q, $v) => $q->where('date', '<=', $v))
                ->when($filters['q'] ?? null, fn ($q, $v) => $q->where('number', 'like', "%{$v}%"))
                ->get()
                ->map(fn (SpoiltCheque $s) => (object) [
                    'kind' => 'spoilt', 'voucher' => null, 'date' => $s->date->toDateString(), 'number' => $s->number, 'type' => 'cheque',
                    'direction' => 'issued', 'bank' => $s->book->account, 'party' => null, 'amount' => 0.0, 'status' => 'spoilt',
                    'bank_date' => null, 'reason' => $s->reason, 'days' => null, 'spoilt' => $s,
                ]);
            $rows = $rows->concat($spoilt);
        }

        return $rows->when($filters['status'] ?? null, fn ($c, $s) => $c->where('status', $s))
            ->sortBy([['date', 'asc'], ['number', 'asc']])->values();
    }

    /**
     * Leaf usage of a cheque book: number => voucher, spoilt or null (unused).
     *
     * @return array{used: Collection, spoilt: Collection, unused: array<int, string>}
     */
    public function usage(ChequeBook $book): array
    {
        $used = Voucher::query()->where('instrument_account_id', $book->account_id)->where('instrument_type', 'cheque')
            ->where('instrument_direction', 'issued')->where('status', '!=', Voucher::CANCELLED)
            ->get()->filter(fn ($v) => $book->contains($v->instrument_number))->keyBy(fn ($v) => (int) $v->instrument_number);
        $spoilt = $book->spoilt()->get()->keyBy(fn ($s) => (int) $s->number);
        $unused = [];
        for ($n = $book->from_number; $n <= $book->to_number && count($unused) < 500; $n++) {
            if (! $used->has($n) && ! $spoilt->has($n)) {
                $unused[] = $book->format($n);
            }
        }

        return ['used' => $used, 'spoilt' => $spoilt, 'unused' => $unused];
    }

    /** The next unused leaf of the bank's active books, for the voucher form. */
    public function nextNumber(int $bankId): ?string
    {
        foreach (ChequeBook::query()->where('account_id', $bankId)->where('is_active', true)->orderBy('from_number')->get() as $book) {
            $usage = $this->usage($book);
            if ($usage['unused']) {
                return $usage['unused'][0];
            }
        }

        return null;
    }

    protected function counterparty(Voucher $v): ?string
    {
        $other = $v->entries->first(fn ($e) => $e->account_id !== $v->instrument_account_id);

        return $other ? Account::query()->whereKey($other->account_id)->value('name') : null;
    }
}
