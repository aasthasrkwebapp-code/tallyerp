<?php

namespace App\Services\Inventory;

use App\Models\Account;
use App\Models\AuditLog;
use App\Models\GrniProvision;
use App\Models\User;
use App\Models\Voucher;
use App\Models\VoucherType;
use App\Services\Accounting\VoucherService;
use App\Services\Setup\DefaultChartOfAccounts;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Goods received not invoiced (GRNI).
 *
 * A goods receipt note brings stock in, so closing stock rises, but no purchase is booked
 * until the supplier's bill arrives. At a period end that overstates profit. The provision
 * books the unbilled value of GRN lines on the date: debit each item's purchase ledger,
 * credit "Goods Received Not Invoiced" (under Provisions). It is normally reversed the next
 * day, so the bill, when posted, books the purchase once.
 */
class GrniService
{
    public function __construct(protected DeliveryNoteService $notes, protected VoucherService $vouchers) {}

    /**
     * GRN lines not billed on the date, and the totals per purchase ledger.
     *
     * @return array{rows: Collection, unvalued: Collection, ledgers: Collection, total: int}
     */
    public function preview(string $asOf): array
    {
        $rows = $this->notes->pending('receipt_note', null, $asOf);
        $default = Account::idFor('purchase');
        $valued = $rows->filter(fn ($r) => $r->value > 0)->values();
        $names = Account::query()->whereIn('id', $valued->map(fn ($r) => $r->line->item?->purchase_account_id ?? $default)->unique())->pluck('name', 'id');
        $ledgers = $valued->groupBy(fn ($r) => $r->line->item?->purchase_account_id ?? $default)
            ->map(fn ($g, $id) => (object) ['account_id' => (int) $id, 'name' => $names[$id] ?? 'Purchase', 'amount' => $g->sum('value'), 'lines' => $g->count()])
            ->sortBy('name')->values();

        return ['rows' => $valued, 'unvalued' => $rows->filter(fn ($r) => $r->value === 0)->values(), 'ledgers' => $ledgers, 'total' => $valued->sum('value')];
    }

    public function post(string $asOf, bool $reverse, User $user, bool $automatic = false): GrniProvision
    {
        if (! $asOf || ! strtotime($asOf)) {
            throw ValidationException::withMessages(['date' => 'Enter the date of the provision.']);
        }
        $asOf = Carbon::parse($asOf)->toDateString();
        if (GrniProvision::query()->where('status', 'posted')->where('date', $asOf)->exists()) {
            throw ValidationException::withMessages(['date' => 'A provision is already posted for this date. Cancel it first to post it again.']);
        }
        $preview = $this->preview($asOf);
        if ($preview['total'] <= 0) {
            throw ValidationException::withMessages(['date' => 'There are no unbilled goods receipt notes with a value on this date.']);
        }
        $type = VoucherType::forBase('journal');
        if (! $type) {
            throw ValidationException::withMessages(['date' => 'The Journal voucher type is needed.']);
        }

        return DB::transaction(function () use ($asOf, $reverse, $user, $preview, $type, $automatic) {
            $grni = DefaultChartOfAccounts::ledgerId('grni');
            $provision = GrniProvision::create([
                'date' => $asOf, 'amount' => Money::fromPaise($preview['total']), 'note_lines' => $preview['rows']->count(),
                'status' => 'posted', 'automatic' => $automatic, 'created_by' => $user->id,
            ]);
            $debits = $preview['ledgers']->map(fn ($l) => ['account_id' => $l->account_id, 'side' => 'Dr', 'amount' => Money::fromPaise($l->amount)])->all();
            $credit = ['account_id' => $grni, 'side' => 'Cr', 'amount' => Money::fromPaise($preview['total'])];
            $label = Carbon::parse($asOf)->format('d M Y');
            try {
                $voucher = $this->vouchers->create([
                    'voucher_type_id' => $type->id, 'date' => $asOf,
                    'narration' => "Provision for goods received not invoiced on {$label} ({$preview['rows']->count()} GRN lines)",
                    'entries' => [...$debits, $credit],
                ], $user, Voucher::POSTED, $provision);
                $reversal = null;
                if ($reverse) {
                    $next = Carbon::parse($asOf)->addDay()->toDateString();
                    $flip = fn ($e) => ['side' => $e['side'] === 'Dr' ? 'Cr' : 'Dr'] + $e;
                    $reversal = $this->vouchers->create([
                        'voucher_type_id' => $type->id, 'date' => $next,
                        'narration' => "Reversal of the provision for goods received not invoiced on {$label}",
                        'entries' => array_map($flip, [$credit, ...$debits]),
                    ], $user, Voucher::POSTED, $provision);
                }
            } catch (ValidationException $e) {
                throw ValidationException::withMessages(['date' => collect($e->errors())->flatten()->all()]);
            }
            $provision->update(['voucher_id' => $voucher->id, 'reversal_voucher_id' => $reversal?->id]);
            AuditLog::record('posted', $provision, null, ['amount' => $provision->amount], ($automatic ? 'Automatic ' : '')."GRNI provision on {$label} posted as {$voucher->voucher_number}".($reversal ? ", reversed by {$reversal->voucher_number}" : ''));

            return $provision->fresh(['voucher', 'reversal']);
        });
    }

    /**
     * The automatic month-end run: posts the provision for the last day of the month before
     * $today when none exists for that date. A provision cancelled by hand is not posted again.
     * The outcome is kept in the company setting grni_auto_last so the GRNI page can show it.
     *
     * @return array{date: string, status: string, message: string}
     */
    public function runMonthEnd(\App\Models\Company $company, User $user, string $today): array
    {
        $date = Carbon::parse($today)->subMonthNoOverflow()->endOfMonth()->toDateString();
        $existing = GrniProvision::query()->where('date', $date)->orderByDesc('id')->first();
        if ($existing) {
            return ['date' => $date, 'status' => 'skipped', 'message' => $existing->status === 'posted' ? 'Already posted.' : 'Cancelled by hand; not posted again.'];
        }
        if (! $company->financialYearFor($date)) {
            return ['date' => $date, 'status' => 'skipped', 'message' => 'The date is outside the books.'];
        }
        try {
            $provision = $this->post($date, (bool) $company->setting('grni_auto_reverse', true), $user, true);
            $result = ['date' => $date, 'status' => 'posted', 'message' => 'Posted '.Money::format($provision->amount).' as '.$provision->voucher->voucher_number.'.'];
        } catch (ValidationException $e) {
            $message = collect($e->errors())->flatten()->implode(' ');
            $nothing = str_starts_with($message, 'There are no unbilled');
            $result = ['date' => $date, 'status' => $nothing ? 'nothing' : 'failed', 'message' => $nothing ? 'Nothing to provide for.' : $message];
        }
        $company->update(['settings' => array_merge($company->settings ?? [], ['grni_auto_last' => $result + ['ran_at' => now()->toDateTimeString()]])]);

        return $result;
    }

    public function cancel(GrniProvision $provision, User $user, string $reason): GrniProvision
    {
        if ($provision->status !== 'posted') {
            throw ValidationException::withMessages(['reason' => 'This provision is already cancelled.']);
        }

        return DB::transaction(function () use ($provision, $user, $reason) {
            foreach ([$provision->voucher, $provision->reversal] as $voucher) {
                if ($voucher && ! $voucher->isCancelled()) {
                    $this->vouchers->cancel($voucher, $user, $reason);
                }
            }
            $provision->update(['status' => 'cancelled', 'cancellation_reason' => mb_substr($reason, 0, 255)]);
            AuditLog::record('cancelled', $provision, null, null, "GRNI provision on {$provision->date->format('d M Y')} cancelled: {$reason}");

            return $provision;
        });
    }
}
