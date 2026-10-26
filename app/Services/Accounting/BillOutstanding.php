<?php

namespace App\Services\Accounting;

use App\Models\Voucher;
use App\Support\Money;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Pending bills of a bill-wise party ledger, derived from posted bill allocations.
 *
 * A bill is opened by a "new_ref" or "advance" allocation and settled by
 * "agst_ref" allocations with the same reference on the opposite side.
 */
class BillOutstanding
{
    /**
     * @return Collection<int, object{bill_ref:string, side:string, original:int, pending:int, date:?string, due_date:?string, voucher_id:int}>
     *   pending > 0 means the bill is open on its original side.
     */
    public function bills(int $accountId, ?int $excludeVoucherId = null, ?string $asOf = null): Collection
    {
        $rows = DB::table('bill_allocations as b')
            ->join('voucher_entries as e', 'e.id', '=', 'b.voucher_entry_id')
            ->join('vouchers as v', 'v.id', '=', 'e.voucher_id')
            ->where('b.account_id', $accountId)
            ->where('v.status', Voucher::POSTED)
            ->whereNotNull('b.bill_ref')
            ->when($excludeVoucherId, fn ($q) => $q->where('v.id', '!=', $excludeVoucherId))
            ->when($asOf, fn ($q) => $q->where('v.date', '<=', $asOf))
            ->orderBy('v.date')->orderBy('v.id')
            ->get(['b.bill_ref', 'b.bill_type', 'b.amount', 'b.side', 'b.due_date', 'v.date', 'v.id as voucher_id']);

        return $rows->groupBy(fn ($r) => mb_strtolower($r->bill_ref))->map(function (Collection $items) {
            $opening = $items->first(fn ($r) => in_array($r->bill_type, ['new_ref', 'advance'], true)) ?? $items->first();
            $net = $items->sum(fn ($r) => ($r->side === 'Dr' ? 1 : -1) * Money::toPaise($r->amount));
            $originalSign = $opening->side === 'Dr' ? 1 : -1;

            return (object) [
                'bill_ref' => $opening->bill_ref,
                'bill_type' => $opening->bill_type,
                'side' => $opening->side,
                'original' => Money::toPaise($opening->amount),
                'pending' => $net * $originalSign,
                'net' => $net,
                'date' => $opening->date,
                'due_date' => $opening->due_date,
                'voucher_id' => $opening->voucher_id,
            ];
        })->values();
    }

    /** Bills with a non-zero pending amount. */
    public function open(int $accountId, ?int $excludeVoucherId = null, ?string $asOf = null): Collection
    {
        return $this->bills($accountId, $excludeVoucherId, $asOf)->filter(fn ($b) => $b->pending !== 0)->values();
    }

    public function find(int $accountId, string $billRef, ?int $excludeVoucherId = null): ?object
    {
        $key = mb_strtolower($billRef);

        return $this->bills($accountId, $excludeVoucherId)->first(fn ($b) => mb_strtolower($b->bill_ref) === $key);
    }
}
