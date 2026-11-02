<?php

namespace App\Services\Banking;

use App\Models\Account;
use App\Models\AuditLog;
use App\Models\PositivePayBatch;
use App\Models\PositivePayItem;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Positive pay (CTS Positive Pay System): the details of issued cheques the
 * bank checks before paying them.
 *
 * Due for a bank: its issued cheques at or above the threshold that are
 * posted and not yet cleared (pending or post-dated) and not yet reported,
 * plus cheques already reported that have since been cancelled or reversed,
 * which go out as cancellations. Each file keeps a copy of what it carried,
 * so it can be downloaded again exactly as sent.
 */
class PositivePay
{
    /** RBI recommends positive pay for cheques of ₹50,000 and above. */
    public const DEFAULT_THRESHOLD = '50000';

    public const HEADERS = ['Account Number', 'IFSC', 'Cheque Number', 'Cheque Date', 'Amount', 'Payee Name', 'Action'];

    public function __construct(protected ChequeRegister $register) {}

    /** @return array{issue: Collection, cancel: Collection} */
    public function due(Account $bank, string $threshold): array
    {
        $rows = $this->register->rows(['bank' => $bank->id, 'direction' => 'issued', 'type' => 'cheque'])->where('kind', 'voucher');
        $reported = PositivePayItem::query()->whereIn('voucher_id', $rows->pluck('voucher.id'))->get()->groupBy('voucher_id');
        $min = Money::toPaise($threshold);

        $issue = $rows->filter(fn ($r) => in_array($r->status, ['pending', 'post_dated'], true)
            && Money::toPaise($r->amount) >= $min && ! ($reported[$r->voucher->id] ?? collect())->contains('action', 'issue'))->values();
        $cancel = $rows->filter(function ($r) use ($reported) {
            $items = $reported[$r->voucher->id] ?? collect();

            return in_array($r->status, ['cancelled', 'reversed'], true) && $items->contains('action', 'issue') && ! $items->contains('action', 'cancel');
        })->values();

        return ['issue' => $issue, 'cancel' => $cancel];
    }

    public function create(Account $bank, string $threshold, User $user): PositivePayBatch
    {
        if (! $bank->bank_account_number) {
            throw ValidationException::withMessages(['bank' => "Enter the account number of {$bank->name} on its ledger first; the bank matches cheques by it."]);
        }
        ['issue' => $issue, 'cancel' => $cancel] = $this->due($bank, $threshold);
        if ($issue->isEmpty() && $cancel->isEmpty()) {
            throw ValidationException::withMessages(['bank' => 'There are no cheques to report.']);
        }

        return DB::transaction(function () use ($bank, $threshold, $user, $issue, $cancel) {
            $batch = PositivePayBatch::create(['account_id' => $bank->id, 'threshold' => $threshold, 'created_by' => $user->id]);
            $total = 0;
            foreach ([['issue', $issue], ['cancel', $cancel]] as [$action, $rows]) {
                foreach ($rows as $r) {
                    $batch->items()->create([
                        'voucher_id' => $r->voucher->id, 'action' => $action, 'cheque_number' => $r->number,
                        'cheque_date' => $r->date, 'amount' => Money::normalize($r->amount), 'payee_name' => $r->party,
                    ]);
                    $total += $action === 'issue' ? Money::toPaise($r->amount) : 0;
                }
            }
            $batch->update(['item_count' => $issue->count() + $cancel->count(), 'total' => Money::fromPaise($total)]);
            AuditLog::record('positive_pay_created', $batch, null, ['issued' => $issue->count(), 'cancelled' => $cancel->count()],
                "Positive pay file for {$bank->name}: {$issue->count()} cheque(s), {$cancel->count()} cancellation(s)");

            return $batch;
        });
    }

    /** File rows for a batch, as sent. */
    public function lines(PositivePayBatch $batch): Collection
    {
        $bank = $batch->account;

        return $batch->items()->orderBy('action', 'desc')->orderBy('cheque_number')->get()->map(fn (PositivePayItem $i) => [
            $bank->bank_account_number, $bank->ifsc, $i->cheque_number, $i->cheque_date->format('d-m-Y'),
            Money::normalize($i->amount), $i->payee_name, $i->action === 'issue' ? 'I' : 'C',
        ]);
    }
}
