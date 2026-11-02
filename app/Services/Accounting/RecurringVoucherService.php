<?php

namespace App\Services\Accounting;

use App\Models\AuditLog;
use App\Models\RecurringVoucher;
use App\Models\RecurringVoucherRun;
use App\Models\User;
use App\Models\Voucher;
use App\Models\VoucherType;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Saves recurring voucher templates and makes their vouchers when due.
 *
 * A due date that cannot be posted (locked period, no financial year,
 * an inactive ledger) is logged as failed and stays due, so it is retried
 * on the next run instead of being skipped silently.
 */
class RecurringVoucherService
{
    /** Most vouchers one template makes in a single run (catching up a long gap). */
    public const MAX_PER_RUN = 36;

    public function __construct(protected VoucherService $vouchers) {}

    /**
     * @param  array{name: string, voucher_type_id: int, frequency: string, start_date: string, end_date: ?string, mode: string,
     *               reference: ?string, narration: ?string, is_active?: bool, next_date?: ?string,
     *               entries: array<int, array{account_id: ?int, side: ?string, amount: ?string, cost_centre_id: ?int, narration: ?string, bill_type?: ?string, due_days?: ?int}>}  $data
     */
    public function save(?RecurringVoucher $recurring, array $data, User $user): RecurringVoucher
    {
        $type = VoucherType::query()->whereKey($data['voucher_type_id'] ?? 0)->first();
        if ($type && ! $type->isAutoNumbered()) {
            throw ValidationException::withMessages(['voucher_type_id' => 'Recurring vouchers need a voucher type with automatic numbering.']);
        }
        if (! array_key_exists($data['frequency'] ?? '', RecurringVoucher::FREQUENCIES)) {
            throw ValidationException::withMessages(['frequency' => 'Select how often the voucher repeats.']);
        }
        if (! empty($data['end_date']) && $data['end_date'] < $data['start_date']) {
            throw ValidationException::withMessages(['end_date' => 'The end date cannot be before the start date.']);
        }

        $entries = collect($data['entries'] ?? [])
            ->filter(fn ($e) => ! empty($e['account_id']) || trim((string) ($e['amount'] ?? '')) !== '')
            ->values()
            ->map(function ($e) {
                $bill = in_array($e['bill_type'] ?? null, ['new_ref', 'on_account'], true) ? $e['bill_type'] : null;

                return [
                    'account_id' => $e['account_id'] ?? null,
                    'side' => $e['side'] ?? null,
                    'amount' => $e['amount'] ?? null,
                    'cost_centre_id' => $e['cost_centre_id'] ?? null,
                    'narration' => $e['narration'] ?? null,
                    'bill_type' => $bill,
                    'due_days' => $bill === 'new_ref' && ($e['due_days'] ?? '') !== '' ? max(0, (int) $e['due_days']) : null,
                ];
            });

        // The lines are checked exactly as a voucher would be, by making one and rolling it back.
        $this->dryRun($data, $entries, $user);

        return DB::transaction(function () use ($recurring, $data, $entries, $user) {
            $attributes = [
                'name' => trim($data['name']),
                'voucher_type_id' => $data['voucher_type_id'],
                'frequency' => $data['frequency'],
                'start_date' => $data['start_date'],
                'end_date' => $data['end_date'] ?? null,
                'mode' => $data['mode'] === 'draft' ? 'draft' : 'post',
                'reference' => $data['reference'] ?? null,
                'narration' => $data['narration'] ?? null,
                'is_active' => (bool) ($data['is_active'] ?? true),
                'total' => Money::fromPaise($entries->where('side', 'Dr')->sum(fn ($e) => Money::toPaise((string) $e['amount']))),
            ];
            if (! $recurring) {
                $attributes['next_date'] = $data['start_date'];
                $attributes['created_by'] = $user->id;
            } elseif (! empty($data['next_date'])) {
                $attributes['next_date'] = $data['next_date'];
            }
            if (! empty($attributes['end_date']) && ($attributes['next_date'] ?? $recurring?->next_date?->toDateString()) > $attributes['end_date']) {
                $attributes['next_date'] = null;
            }

            $recurring ??= new RecurringVoucher;
            $recurring->fill($attributes)->save();
            $recurring->lines()->delete();
            foreach ($entries as $i => $e) {
                $recurring->lines()->create(['line_no' => $i + 1, 'amount' => Money::normalize((string) $e['amount'])] + $e);
            }

            return $recurring->load('lines.account', 'type');
        });
    }

    /** Template values for a new recurring voucher copied from an existing voucher. */
    public function fromVoucher(Voucher $voucher): array
    {
        $input = $this->vouchers->toInput($voucher);
        $date = $voucher->date->copy();

        return [
            'name' => mb_substr(trim(($voucher->narration ?: $voucher->type->name).''), 0, 100),
            'voucher_type_id' => $voucher->voucher_type_id,
            'frequency' => 'monthly',
            'start_date' => $date->copy()->addMonthNoOverflow()->toDateString(),
            'end_date' => null,
            'mode' => 'post',
            'reference' => $voucher->reference,
            'narration' => $voucher->narration,
            'is_active' => true,
            'entries' => collect($input['entries'])->map(function ($e) use ($date) {
                $bill = $e['bills'][0] ?? null;

                return [
                    'account_id' => $e['account_id'],
                    'side' => $e['side'],
                    'amount' => $e['amount'],
                    'cost_centre_id' => $e['cost_centre_id'],
                    'narration' => $e['narration'],
                    // A new bill gets each voucher's own number; settlement of old bills becomes on account.
                    'bill_type' => $bill ? ($bill['bill_type'] === 'new_ref' ? 'new_ref' : 'on_account') : null,
                    'due_days' => $bill && $bill['bill_type'] === 'new_ref' && $bill['due_date'] ? max(0, (int) $date->diffInDays(Carbon::parse($bill['due_date']))) : null,
                ];
            })->all(),
        ];
    }

    /**
     * Make every voucher due on or before $asOf.
     *
     * @return Collection<int, RecurringVoucherRun>
     */
    public function run(RecurringVoucher $recurring, User $user, ?string $asOf = null): Collection
    {
        $asOf ??= now()->toDateString();
        $runs = collect();
        $recurring->loadMissing('lines', 'type');

        while ($recurring->isDue($asOf) && $runs->count() < self::MAX_PER_RUN) {
            $date = $recurring->next_date->toDateString();
            if ($recurring->end_date && $date > $recurring->end_date->toDateString()) {
                $recurring->updateQuietly(['next_date' => null]);
                break;
            }
            try {
                $voucher = $this->vouchers->create($this->voucherInput($recurring, $date), $user,
                    $recurring->mode === 'draft' ? Voucher::DRAFT : $this->vouchers->resolveStatus($user, 'post'));
            } catch (ValidationException $e) {
                $runs->push(RecurringVoucherRun::create([
                    'company_id' => $recurring->company_id, 'recurring_voucher_id' => $recurring->id, 'date' => $date,
                    'status' => 'failed', 'message' => collect($e->errors())->flatten()->unique()->implode(' '), 'run_by' => $user->id,
                ]));
                $recurring->updateQuietly(['last_run_at' => now()]);
                break;
            }

            $runs->push(RecurringVoucherRun::create([
                'company_id' => $recurring->company_id, 'recurring_voucher_id' => $recurring->id, 'date' => $date,
                'voucher_id' => $voucher->id, 'status' => 'created', 'run_by' => $user->id,
            ]));
            AuditLog::record('generated', $voucher, null, ['recurring_voucher_id' => $recurring->id, 'date' => $date],
                "Made from recurring voucher \"{$recurring->name}\" for {$date}");

            $next = $recurring->dateAfter($date)->toDateString();
            $recurring->updateQuietly([
                'next_date' => $recurring->end_date && $next > $recurring->end_date->toDateString() ? null : $next,
                'last_run_at' => now(),
            ]);
        }

        return $runs;
    }

    /**
     * Run every active template of the current company that is due.
     *
     * @return array{created: int, failed: int}
     */
    public function runDue(User $fallbackUser, ?string $asOf = null): array
    {
        $asOf ??= now()->toDateString();
        $created = $failed = 0;
        $due = RecurringVoucher::query()->where('is_active', true)->whereNotNull('next_date')->where('next_date', '<=', $asOf)->with('creator')->orderBy('id')->get();
        foreach ($due as $recurring) {
            // Vouchers are made in the name of the person who set up the schedule, with their rights.
            $user = $recurring->creator && $recurring->creator->companies()->whereKey($recurring->company_id)->wherePivot('is_active', true)->exists()
                ? $recurring->creator : $fallbackUser;
            $runs = $this->run($recurring, $user, $asOf);
            $created += $runs->where('status', 'created')->count();
            $failed += $runs->where('status', 'failed')->count();
        }

        return ['created' => $created, 'failed' => $failed];
    }

    public function voucherInput(RecurringVoucher $recurring, string $date): array
    {
        return [
            'voucher_type_id' => $recurring->voucher_type_id,
            'date' => $date,
            'reference' => $recurring->reference,
            'narration' => $recurring->narration,
            'entries' => $this->entries($recurring->lines->map(fn ($l) => ['amount' => Money::normalize($l->amount)] + $l->only(['account_id', 'side', 'cost_centre_id', 'narration', 'bill_type', 'due_days']))->all(), $date),
        ];
    }

    /** Template lines as voucher entries for $date; a new bill takes the voucher's own number. */
    protected function entries(array $lines, string $date): array
    {
        return array_map(fn ($l) => [
            'account_id' => $l['account_id'],
            'side' => $l['side'],
            'amount' => $l['amount'],
            'cost_centre_id' => $l['cost_centre_id'],
            'narration' => $l['narration'],
            'bills' => $l['bill_type'] ? [[
                'bill_type' => $l['bill_type'],
                'bill_ref' => $l['bill_type'] === 'new_ref' ? Voucher::OWN_NUMBER : null,
                'amount' => $l['amount'],
                'due_date' => $l['bill_type'] === 'new_ref' && $l['due_days'] !== null ? Carbon::parse($date)->addDays($l['due_days'])->toDateString() : null,
            ]] : [],
        ], array_values($lines));
    }

    protected function dryRun(array $data, Collection $entries, User $user): void
    {
        $input = [
            'voucher_type_id' => $data['voucher_type_id'] ?? null,
            'date' => now()->toDateString(),
            'reference' => $data['reference'] ?? null,
            'narration' => $data['narration'] ?? null,
            'entries' => $this->entries($entries->all(), now()->toDateString()),
        ];

        DB::beginTransaction();
        try {
            // Checked as strictly as a posted voucher (balance, ledger rules), without taking a number.
            $this->vouchers->create($input, $user, Voucher::PENDING);
        } catch (ValidationException $e) {
            $messages = collect($e->errors())->except('date')->all();
            if ($messages !== []) {
                throw ValidationException::withMessages($messages);
            }
        } finally {
            DB::rollBack();
        }
    }
}
