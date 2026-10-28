<?php

namespace App\Services\Billing;

use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\OrderAdvance;
use App\Models\OrderAdvanceAdjustment;
use App\Models\User;
use App\Models\Voucher;
use App\Models\VoucherType;
use App\Services\Accounting\BillOutstanding;
use App\Services\Accounting\VoucherService;
use App\Support\Money;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Advances against sales and purchase orders.
 *
 * Recording one posts a receipt (sales order: Dr cash/bank, Cr customer) or payment
 * (purchase order: Dr supplier, Cr cash/bank). On a bill-wise party the party line opens
 * an "Advance" bill named ADV-<order number>-<n>.
 *
 * Adjusting sets an advance off against a posted invoice of the same order with a journal
 * on the party ledger: one line settles the advance bill, the other the invoice's bill
 * (Agst Ref both). The party balance is unchanged; only the bill-wise outstanding moves.
 */
class OrderAdvanceService
{
    public function __construct(
        protected VoucherService $vouchers,
        protected BillOutstanding $bills,
        protected OrderService $orders,
        protected InvoiceService $invoices,
    ) {}

    /** Cash and bank ledgers an advance can be received into or paid from. */
    public function cashBankQuery()
    {
        return Account::query()->where('is_active', true)->whereIn('account_type', ['cash', 'bank']);
    }

    /**
     * @param  array{date: string, amount: string, account_id: int, narration?: ?string}  $data
     */
    public function record(Order $order, array $data, User $user): OrderAdvance
    {
        if ($order->isQuotation() || ! $order->isOpen()) {
            throw ValidationException::withMessages(['amount' => 'Advances can be recorded only against open orders.']);
        }
        $order->loadMissing('party');
        $errors = [];
        try {
            $amount = Money::toPaise($data['amount'] ?? '');
        } catch (\InvalidArgumentException) {
            $amount = 0;
        }
        $already = $order->advances()->where('status', 'posted')->get()->sum(fn ($a) => Money::toPaise($a->amount));
        $limit = Money::toPaise($order->grand_total) - $already;
        if ($amount <= 0) {
            $errors['amount'] = 'Enter an amount greater than zero.';
        } elseif ($amount > $limit) {
            $errors['amount'] = 'Advances would exceed the order value. At most '.Money::format($limit, paiseInput: true).' more can be recorded.';
        }
        $account = $this->cashBankQuery()->find($data['account_id'] ?? 0);
        if (! $account) {
            $errors['account_id'] = 'Choose the cash or bank ledger.';
        }
        $sales = $order->config()['invoice_kind'] === 'sales';
        $type = VoucherType::forBase($sales ? 'receipt' : 'payment');
        if (! $type) {
            $errors['amount'] = 'The '.($sales ? 'Receipt' : 'Payment').' voucher type is needed.';
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        return DB::transaction(function () use ($order, $data, $user, $amount, $account, $sales, $type) {
            $count = $order->advances()->lockForUpdate()->count();
            $billWise = (bool) $order->party->maintain_bill_wise;
            $ref = $billWise ? 'ADV-'.$order->number.'-'.($count + 1) : null;
            $advance = OrderAdvance::create([
                'order_id' => $order->id, 'date' => $data['date'], 'amount' => Money::fromPaise($amount), 'bill_ref' => $ref,
                'account_id' => $account->id, 'status' => 'posted', 'created_by' => $user->id,
            ]);
            $party = ['account_id' => $order->party_account_id, 'side' => $sales ? 'Cr' : 'Dr', 'amount' => Money::fromPaise($amount),
                'bills' => $billWise ? [['bill_type' => 'advance', 'bill_ref' => $ref, 'amount' => Money::fromPaise($amount)]] : []];
            $cash = ['account_id' => $account->id, 'side' => $sales ? 'Dr' : 'Cr', 'amount' => Money::fromPaise($amount)];
            $narration = trim('Advance '.($sales ? 'from ' : 'to ').$order->party_name.' against '.mb_strtolower($order->config()['label']).' '.$order->number.'. '.($data['narration'] ?? ''));
            $voucher = $this->post($type, $data['date'], $narration, $sales ? [$cash, $party] : [$party, $cash], $user, $advance, 'amount');
            $advance->update(['voucher_id' => $voucher->id]);
            AuditLog::record('created', $advance, null, ['amount' => $advance->amount], "Advance of {$advance->amount} against {$order->number} posted as {$voucher->voucher_number}");

            return $advance->fresh('voucher');
        });
    }

    /**
     * What is still open on each posted advance (paise), from the party's bill-wise outstanding.
     *
     * @return array<int, int> advance id => pending paise (null when the party is not bill-wise)
     */
    public function pending(Order $order): array
    {
        $out = [];
        foreach ($order->advances->where('status', 'posted') as $a) {
            $out[$a->id] = $a->bill_ref ? max(0, $this->bills->find($order->party_account_id, $a->bill_ref)?->pending ?? 0) : null;
        }

        return $out;
    }

    /**
     * Posted invoices of the order with what is still pending on their bill.
     *
     * @return Collection<int, object{invoice: Invoice, ref: string, pending: int}>
     */
    public function invoicesWithPending(Order $order): Collection
    {
        return $this->orders->invoicesFor($order)
            ->filter(fn (Invoice $i) => $i->status() === 'posted')
            ->map(function (Invoice $i) use ($order) {
                $ref = $this->invoices->billRef($i);

                return (object) ['invoice' => $i, 'ref' => $ref, 'pending' => max(0, $this->bills->find($order->party_account_id, $ref)?->pending ?? 0)];
            })->values();
    }

    /**
     * @param  array{invoice_id: int, amount: string, date: string}  $data
     */
    public function adjust(OrderAdvance $advance, array $data, User $user): OrderAdvanceAdjustment
    {
        $order = $advance->order()->with('party', 'advances')->firstOrFail();
        if (! $advance->isPosted() || ! $advance->bill_ref) {
            throw ValidationException::withMessages(['adjust' => $advance->bill_ref ? 'This advance is cancelled.' : 'The party is not bill-wise, so the advance already reduces its balance. Nothing to adjust.']);
        }
        $invoice = $this->invoicesWithPending($order)->first(fn ($r) => $r->invoice->id === (int) ($data['invoice_id'] ?? 0));
        try {
            $amount = Money::toPaise($data['amount'] ?? '');
        } catch (\InvalidArgumentException) {
            $amount = 0;
        }
        $open = $this->pending($order)[$advance->id] ?? 0;
        $errors = [];
        if (! $invoice) {
            $errors['adjust'] = 'Choose a posted invoice of this order.';
        } elseif ($amount <= 0 || $amount > min($open, $invoice->pending)) {
            $errors['adjust'] = 'Enter an amount up to '.Money::format(min($open, $invoice->pending), paiseInput: true).' (what is open on both the advance and the invoice).';
        }
        $type = VoucherType::forBase('journal');
        if (! $type) {
            $errors['adjust'] = 'The Journal voucher type is needed.';
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        return DB::transaction(function () use ($advance, $order, $invoice, $amount, $data, $user, $type) {
            $sales = $order->config()['invoice_kind'] === 'sales';
            $adjustment = OrderAdvanceAdjustment::create([
                'order_advance_id' => $advance->id, 'invoice_id' => $invoice->invoice->id, 'date' => $data['date'],
                'amount' => Money::fromPaise($amount), 'status' => 'posted', 'created_by' => $user->id,
            ]);
            $money = Money::fromPaise($amount);
            // Sales: the advance is a Cr bill and the invoice a Dr bill, so Dr settles the advance and Cr the invoice.
            $entries = [
                ['account_id' => $order->party_account_id, 'side' => $sales ? 'Dr' : 'Cr', 'amount' => $money, 'bills' => [['bill_type' => 'agst_ref', 'bill_ref' => $advance->bill_ref, 'amount' => $money]]],
                ['account_id' => $order->party_account_id, 'side' => $sales ? 'Cr' : 'Dr', 'amount' => $money, 'bills' => [['bill_type' => 'agst_ref', 'bill_ref' => $invoice->ref, 'amount' => $money]]],
            ];
            $voucher = $this->post($type, $data['date'], "Advance {$advance->bill_ref} adjusted against {$invoice->invoice->displayNumber()}", $entries, $user, $adjustment, 'adjust');
            $adjustment->update(['voucher_id' => $voucher->id]);
            AuditLog::record('updated', $advance, null, ['adjusted' => $adjustment->amount], "Advance {$advance->bill_ref} adjusted against {$invoice->invoice->displayNumber()} by {$voucher->voucher_number}");

            return $adjustment->fresh('voucher');
        });
    }

    public function cancelAdjustment(OrderAdvanceAdjustment $adjustment, User $user, string $reason): void
    {
        if ($adjustment->status !== 'posted') {
            throw ValidationException::withMessages(['adjust' => 'This adjustment is already cancelled.']);
        }
        DB::transaction(function () use ($adjustment, $user, $reason) {
            if ($adjustment->voucher && ! $adjustment->voucher->isCancelled()) {
                $this->vouchers->cancel($adjustment->voucher, $user, $reason);
            }
            $adjustment->update(['status' => 'cancelled']);
            AuditLog::record('updated', $adjustment->advance, null, null, "Adjustment of {$adjustment->amount} against invoice #{$adjustment->invoice_id} cancelled: {$reason}");
        });
    }

    /** Cancel an advance; refused while any adjustment of it stands. */
    public function cancel(OrderAdvance $advance, User $user, string $reason): void
    {
        if (! $advance->isPosted()) {
            throw ValidationException::withMessages(['advance' => 'This advance is already cancelled.']);
        }
        if ($advance->adjustments()->where('status', 'posted')->exists()) {
            throw ValidationException::withMessages(['advance' => 'Cancel the adjustments of this advance first.']);
        }
        DB::transaction(function () use ($advance, $user, $reason) {
            if ($advance->voucher && ! $advance->voucher->isCancelled()) {
                $this->vouchers->cancel($advance->voucher, $user, $reason);
            }
            $advance->update(['status' => 'cancelled', 'cancellation_reason' => mb_substr($reason, 0, 255)]);
            AuditLog::record('cancelled', $advance, null, null, "Advance {$advance->bill_ref} cancelled: {$reason}");
        });
    }

    protected function post(VoucherType $type, string $date, string $narration, array $entries, User $user, $source, string $errorKey): Voucher
    {
        try {
            return $this->vouchers->create(['voucher_type_id' => $type->id, 'date' => $date, 'narration' => mb_substr($narration, 0, 2000), 'entries' => $entries], $user, Voucher::POSTED, $source);
        } catch (ValidationException $e) {
            throw ValidationException::withMessages(collect($e->errors())->mapWithKeys(fn ($m, $k) => [str_starts_with($k, 'date') ? 'date' : $errorKey => $m])->all());
        }
    }
}
