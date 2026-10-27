<?php

namespace App\Services\Billing;

use App\Models\AuditLog;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\StockItem;
use App\Models\User;
use App\Models\Voucher;
use App\Support\CurrentCompany;
use App\Support\Decimal;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Sales and purchase orders: saving, delivery against invoices, closing.
 *
 * An order line is fulfilled by posted sales invoices (or purchase bills)
 * whose items point at it. An order can be altered or cancelled only while
 * nothing has been invoiced against it; after that it can be short-closed,
 * which stops further invoicing of the pending quantity.
 *
 * Input: date, delivery_date, party_account_id, reference, reference_date,
 * place_of_supply, narration, terms,
 * items: [stock_item_id, description, hsn_sac, quantity, unit, rate, discount_percent, gst_rate]
 */
class OrderService
{
    public function __construct(
        protected CurrentCompany $current,
        protected InvoiceService $invoices,
        protected InvoiceCalculator $calculator,
    ) {}

    public function save(?Order $order, string $kind, array $data, User $user): Order
    {
        $config = Order::kindConfig($kind);
        if ($order && ! $order->isOpen()) {
            throw ValidationException::withMessages(['order' => 'A '.($order->status === Order::CANCELLED ? 'cancelled' : 'closed').' order cannot be altered.']);
        }
        // Any invoice line (even a draft) pointing at the order fixes its lines.
        if ($order && \App\Models\InvoiceItem::query()->whereIn('order_item_id', $order->items()->pluck('id'))->exists()) {
            throw ValidationException::withMessages(['order' => 'Invoices (or drafts) have been made against this order, so it cannot be altered. Close it and make a new order instead.']);
        }

        [$party, $items, $taxMode, $result, $date] = $this->prepare($kind, $config, $data);

        return DB::transaction(function () use ($order, $kind, $data, $user, $party, $items, $taxMode, $result, $date, $config) {
            $totals = $result['totals'];
            $order ??= new Order(['kind' => $kind, 'status' => Order::OPEN, 'created_by' => $user->id, 'number' => $this->nextNumber($kind, $config['prefix'])]);
            $order->fill([
                'date' => $date,
                'delivery_date' => $data['delivery_date'] ?? null,
                'party_account_id' => $party->id,
                'party_name' => $party->name,
                'reference' => $data['reference'] ?? null,
                'reference_date' => $data['reference_date'] ?? null,
                'place_of_supply' => $data['place_of_supply'] ?? $party->partyDetails?->state,
                'tax_mode' => $taxMode,
                'gross_total' => Money::fromPaise($totals['gross']),
                'discount_total' => Money::fromPaise($totals['discount']),
                'taxable_total' => Money::fromPaise($totals['taxable']),
                'cgst_total' => Money::fromPaise($totals['cgst']),
                'sgst_total' => Money::fromPaise($totals['sgst']),
                'igst_total' => Money::fromPaise($totals['igst']),
                'grand_total' => Money::fromPaise($totals['grand']),
                'narration' => $data['narration'] ?? null,
                'terms' => $data['terms'] ?? null,
                'updated_by' => $user->id,
            ]);
            $order->save();

            $order->items()->delete();
            foreach ($items as $n => $item) {
                $line = $result['lines'][$n];
                $order->items()->create([
                    'company_id' => $order->company_id,
                    'line_no' => $n + 1,
                    'stock_item_id' => $item['stock_item_id'],
                    'description' => $item['description'],
                    'hsn_sac' => $item['hsn_sac'],
                    'quantity' => $item['quantity'],
                    'unit' => $item['unit'],
                    'rate' => Money::normalize($item['rate']),
                    'discount_percent' => $item['discount_percent'],
                    'gst_rate' => $taxMode === 'none' ? '0' : $item['gst_rate'],
                    'taxable_amount' => Money::fromPaise($line['taxable']),
                    'cgst_amount' => Money::fromPaise($line['cgst']),
                    'sgst_amount' => Money::fromPaise($line['sgst']),
                    'igst_amount' => Money::fromPaise($line['igst']),
                    'total_amount' => Money::fromPaise($line['total']),
                ]);
            }

            return $order->fresh(['items.stockItem.unit', 'party.partyDetails']);
        });
    }

    /** Cancel an order nothing has been invoiced against. */
    public function cancel(Order $order, User $user, string $reason): Order
    {
        if (! $order->isOpen()) {
            throw ValidationException::withMessages(['order' => 'Only open orders can be cancelled.']);
        }
        if ($this->invoicedLines($order)->isNotEmpty()) {
            throw ValidationException::withMessages(['order' => 'Invoices have been made against this order. Close it instead of cancelling.']);
        }

        return $this->setStatus($order, Order::CANCELLED, $user, $reason);
    }

    /** Short-close: the pending quantity will not be supplied. */
    public function close(Order $order, User $user, string $reason): Order
    {
        if (! $order->isOpen()) {
            throw ValidationException::withMessages(['order' => 'Only open orders can be closed.']);
        }

        return $this->setStatus($order, Order::CLOSED, $user, $reason);
    }

    public function reopen(Order $order, User $user): Order
    {
        if ($order->status !== Order::CLOSED) {
            throw ValidationException::withMessages(['order' => 'Only closed orders can be reopened.']);
        }

        return $this->setStatus($order, Order::OPEN, $user, null, 'reopened');
    }

    /**
     * Quantity invoiced (posted) against each order line, in thousandths.
     *
     * @param  int|null  $exceptInvoiceId  leave out this invoice (the one being edited)
     * @return array<int, int> order item id => quantity
     */
    public function delivered(array $orderItemIds, ?int $exceptInvoiceId = null): array
    {
        if (! $orderItemIds) {
            return [];
        }

        return DB::table('invoice_items as ii')
            ->join('invoices as i', 'i.id', '=', 'ii.invoice_id')
            ->join('vouchers as v', 'v.id', '=', 'i.voucher_id')
            ->where('v.status', Voucher::POSTED)
            ->whereIn('ii.order_item_id', $orderItemIds)
            ->when($exceptInvoiceId, fn ($q) => $q->where('i.id', '!=', $exceptInvoiceId))
            ->get(['ii.order_item_id', 'ii.quantity'])
            ->groupBy('order_item_id')
            ->map(fn ($rows) => $rows->sum(fn ($r) => Decimal::toScaled($r->quantity, 3)))
            ->all();
    }

    /**
     * Lines of an order with ordered, delivered and pending quantity and pending value.
     *
     * @return Collection<int, object{item: OrderItem, ordered:int, delivered:int, pending:int, pending_value:int}>
     */
    public function progress(Order $order): Collection
    {
        $order->loadMissing('items.stockItem.unit');
        $delivered = $this->delivered($order->items->pluck('id')->all());

        return $order->items->map(function (OrderItem $item) use ($delivered, $order) {
            $ordered = Decimal::toScaled($item->quantity, 3);
            $done = $delivered[$item->id] ?? 0;
            $pending = $order->isOpen() ? max(0, $ordered - $done) : 0;

            return (object) [
                'item' => $item,
                'ordered' => $ordered,
                'delivered' => $done,
                'pending' => $pending,
                'pending_value' => $ordered > 0 ? Decimal::mulDiv($pending, Money::toPaise($item->taxable_amount), $ordered) : 0,
            ];
        });
    }

    /** "Pending", "Part delivered", "Delivered", "Closed" or "Cancelled". */
    public function statusLabel(Order $order, ?Collection $progress = null): string
    {
        if ($order->status === Order::CANCELLED) {
            return 'Cancelled';
        }
        if ($order->status === Order::CLOSED) {
            return 'Closed';
        }
        $progress ??= $this->progress($order);
        if ($progress->every(fn ($p) => $p->delivered >= $p->ordered)) {
            return 'Delivered';
        }

        return $progress->contains(fn ($p) => $p->delivered > 0) ? 'Part delivered' : 'Pending';
    }

    /** Invoice form input for the pending lines of an open order. */
    public function invoiceInput(Order $order): array
    {
        if (! $order->isOpen()) {
            throw ValidationException::withMessages(['order' => 'This order is '.$order->status.'.']);
        }
        $config = $order->config();
        $ledgerColumn = $config['invoice_kind'] === 'sales' ? 'sales_account_id' : 'purchase_account_id';

        $items = $this->progress($order)->filter(fn ($p) => $p->pending > 0)->map(fn ($p) => [
            'order_item_id' => $p->item->id,
            'stock_item_id' => $p->item->stock_item_id,
            'account_id' => $p->item->stockItem?->{$ledgerColumn},
            'description' => $p->item->description,
            'hsn_sac' => $p->item->hsn_sac,
            'quantity' => Decimal::trim(Decimal::fromScaled($p->pending, 3)),
            'unit' => $p->item->unit,
            'rate' => $p->item->rate,
            'discount_percent' => $p->item->discount_percent,
            'gst_rate' => $p->item->gst_rate,
        ])->values()->all();
        if ($items === []) {
            throw ValidationException::withMessages(['order' => 'Everything on this order has been invoiced.']);
        }

        return [
            'party_account_id' => $order->party_account_id,
            'place_of_supply' => $order->place_of_supply,
            'reference' => $config['invoice_kind'] === 'sales' ? ($order->reference ?: $order->number) : null,
            'reference_date' => $config['invoice_kind'] === 'sales' ? ($order->reference_date ?? $order->date)?->toDateString() : null,
            'narration' => 'Against '.mb_strtolower($config['label']).' '.$order->number,
            'items' => $items,
        ];
    }

    /**
     * Check the order links on invoice lines: an open order of the right kind,
     * the same party and item, and no more than the pending quantity.
     *
     * @param  array<int, array{key:string, label:string, order_item_id:int, stock_item_id:?int, quantity:string}>  $lines
     * @return array<string, string> errors
     */
    public function checkInvoiceLines(string $invoiceKind, int $partyId, array $lines, ?Invoice $invoice): array
    {
        $orderKind = collect(Order::kinds())->search(fn ($c) => $c['invoice_kind'] === $invoiceKind);
        $errors = [];
        if (! $lines) {
            return [];
        }
        if (! $orderKind) {
            foreach ($lines as $l) {
                $errors[$l['key'].'.order_item_id'] = "{$l['label']}: only sales invoices and purchase bills can be made against orders.";
            }

            return $errors;
        }

        $orderItems = OrderItem::query()->with('order')->whereIn('id', array_column($lines, 'order_item_id'))->get()->keyBy('id');
        $delivered = $this->delivered($orderItems->keys()->all(), $invoice?->id);
        $previous = $invoice ? $invoice->items()->whereNotNull('order_item_id')->pluck('order_item_id')->all() : [];
        $used = [];

        foreach ($lines as $l) {
            $oi = $orderItems->get($l['order_item_id']);
            $key = $l['key'].'.order_item_id';
            if (! $oi || $oi->order->kind !== $orderKind || $oi->order->party_account_id !== $partyId) {
                $errors[$key] = "{$l['label']}: the order line does not belong to an order of this party.";

                continue;
            }
            // An order closed after this invoice was made still accepts its own lines.
            if (! $oi->order->isOpen() && ! in_array($oi->id, $previous, true)) {
                $errors[$key] = "{$l['label']}: order {$oi->order->number} is {$oi->order->status}.";

                continue;
            }
            if ((int) ($l['stock_item_id'] ?? 0) !== (int) $oi->stock_item_id) {
                $errors[$key] = "{$l['label']}: the item differs from line {$oi->line_no} of order {$oi->order->number}.";

                continue;
            }
            $qty = Decimal::toScaled((string) $l['quantity'], 3);
            $pending = Decimal::toScaled($oi->quantity, 3) - ($delivered[$oi->id] ?? 0) - ($used[$oi->id] ?? 0);
            if ($qty > $pending) {
                $errors[$l['key'].'.quantity'] = "{$l['label']}: only ".Decimal::trim(Decimal::fromScaled(max(0, $pending), 3))." is pending on order {$oi->order->number}.";

                continue;
            }
            $used[$oi->id] = ($used[$oi->id] ?? 0) + $qty;
        }

        return $errors;
    }

    /** Posted invoices against an order. */
    public function invoicesFor(Order $order): Collection
    {
        return Invoice::query()->whereHas('items', fn ($q) => $q->whereIn('order_item_id', $order->items()->pluck('id')))
            ->with('voucher')->orderBy('date')->get();
    }

    /**
     * Pending lines of open orders for the pending orders report.
     *
     * @return Collection<int, object> progress rows with the order attached
     */
    public function pending(string $kind, ?int $partyId = null, ?string $asOf = null): Collection
    {
        $orders = Order::query()->ofKind($kind)->where('status', Order::OPEN)
            ->when($partyId, fn ($q) => $q->where('party_account_id', $partyId))
            ->when($asOf, fn ($q) => $q->where('date', '<=', $asOf))
            ->with('items.stockItem.unit')->orderBy('date')->orderBy('id')->get();
        $delivered = $this->delivered($orders->flatMap->items->pluck('id')->all());

        return $orders->flatMap(fn (Order $o) => $o->items->map(function (OrderItem $item) use ($o, $delivered) {
            $ordered = Decimal::toScaled($item->quantity, 3);
            $done = $delivered[$item->id] ?? 0;
            $pending = max(0, $ordered - $done);

            return (object) [
                'order' => $o, 'item' => $item, 'ordered' => $ordered, 'delivered' => $done, 'pending' => $pending,
                'pending_value' => $ordered > 0 ? Decimal::mulDiv($pending, Money::toPaise($item->taxable_amount), $ordered) : 0,
            ];
        }))->filter(fn ($r) => $r->pending > 0)->values();
    }

    // ------------------------------------------------------------------

    protected function invoicedLines(Order $order): Collection
    {
        return collect($this->delivered($order->items()->pluck('id')->all()))->filter();
    }

    protected function setStatus(Order $order, string $status, User $user, ?string $reason, ?string $event = null): Order
    {
        $old = $order->status;
        $order->update(['status' => $status, 'status_reason' => $reason ? mb_substr($reason, 0, 255) : null, 'status_at' => now(), 'status_by' => $user->id]);
        AuditLog::record($event ?? $status, $order, ['status' => $old], ['status' => $status],
            $order->config()['label']." {$order->number} ".($event ?? $status).($reason ? ": {$reason}" : ''));

        return $order;
    }

    protected function prepare(string $kind, array $config, array $data): array
    {
        $errors = [];
        $party = $this->invoices->partyQuery($config['invoice_kind'])->where('account_type', 'party')->with('partyDetails')->find($data['party_account_id'] ?? 0);
        if (! $party) {
            throw ValidationException::withMessages(['party_account_id' => 'Select a '.($config['party_group'] === 'sundry_debtors' ? 'customer' : 'supplier').'.']);
        }

        try {
            $date = Carbon::parse((string) ($data['date'] ?? ''))->toDateString();
        } catch (\Throwable) {
            throw ValidationException::withMessages(['date' => 'Enter a valid date.']);
        }
        if (! $this->current->get()->financialYearFor($date)) {
            throw ValidationException::withMessages(['date' => 'The date is outside every financial year of this company.']);
        }

        $rows = array_values($data['items'] ?? []);
        $stockItems = StockItem::query()->with('unit')->whereIn('id', array_filter(array_map(fn ($r) => (int) ($r['stock_item_id'] ?? 0), $rows)))->get()->keyBy('id');
        $allowedRates = config('erp.gst_rates');
        $items = [];
        foreach ($rows as $i => $row) {
            if (empty($row['stock_item_id']) && trim((string) ($row['description'] ?? '')) === '' && trim((string) ($row['rate'] ?? '')) === '') {
                continue;
            }
            $line = 'Item '.(count($items) + 1);
            $stock = null;
            if (! empty($row['stock_item_id'])) {
                $stock = $stockItems->get((int) $row['stock_item_id']);
                if (! $stock || ! $stock->is_active) {
                    $errors["items.$i.stock_item_id"] = "{$line}: choose an active stock item.";

                    continue;
                }
            }
            $description = mb_substr(trim((string) ($row['description'] ?? '')) ?: (string) $stock?->name, 0, 500);
            if ($description === '') {
                $errors["items.$i.description"] = "{$line}: choose a stock item or enter a description.";
            }
            $quantity = str_replace(',', '', trim((string) ($row['quantity'] ?? '')));
            $rate = str_replace(',', '', trim((string) ($row['rate'] ?? '')));
            $discount = trim((string) ($row['discount_percent'] ?? '')) ?: '0';
            $gstInput = trim((string) ($row['gst_rate'] ?? ''));
            $gstRate = Decimal::trim($gstInput !== '' ? $gstInput : ($stock?->gst_rate ?? '0'));

            if (! preg_match('/^\d{1,7}(\.\d{1,3})?$/', $quantity) || (float) $quantity <= 0) {
                $errors["items.$i.quantity"] = "{$line}: enter a quantity greater than zero (up to 3 decimals).";
            } elseif ($stock && str_contains($quantity, '.') && strlen(rtrim(explode('.', $quantity)[1], '0')) > $stock->unit->decimal_places) {
                $errors["items.$i.quantity"] = "{$line}: unit {$stock->unit->symbol} allows {$stock->unit->decimal_places} decimal places.";
            }
            if (! preg_match('/^\d{1,11}(\.\d{1,2})?$/', $rate)) {
                $errors["items.$i.rate"] = "{$line}: enter a valid rate (up to 2 decimals).";
            }
            if (! preg_match('/^\d{1,3}(\.\d{1,2})?$/', $discount) || (float) $discount > 100) {
                $errors["items.$i.discount_percent"] = "{$line}: the discount must be between 0 and 100%.";
            }
            if (! in_array($gstRate, $allowedRates, true)) {
                $errors["items.$i.gst_rate"] = "{$line}: choose a valid GST rate.";
            }
            $hsn = trim((string) ($row['hsn_sac'] ?? '')) ?: $stock?->hsn_sac;
            if ($hsn && ! preg_match('/^\d{4,8}$/', $hsn)) {
                $errors["items.$i.hsn_sac"] = "{$line}: HSN / SAC must be 4 to 8 digits.";
            }

            $items[] = [
                'stock_item_id' => $stock?->id,
                'description' => $description,
                'hsn_sac' => $hsn ?: null,
                'quantity' => $quantity,
                'unit' => mb_substr(trim((string) ($row['unit'] ?? '')), 0, 20) ?: $stock?->unit->symbol,
                'rate' => $rate,
                'discount_percent' => $discount,
                'gst_rate' => $gstRate,
            ];
        }
        if ($items === [] && ! $errors) {
            $errors['items'] = 'Add at least one item.';
        }
        if (count($items) > 200) {
            $errors['items'] = 'An order can have at most 200 items.';
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        $details = $party->partyDetails;
        $purchase = $config['invoice_kind'] === 'purchase';
        $state = $purchase ? ($details?->state ?? $data['place_of_supply'] ?? null) : ($data['place_of_supply'] ?? $details?->state);
        $taxMode = $this->invoices->taxMode($config['invoice_kind'], $state, $details?->gst_registration_type);

        try {
            $result = $this->calculator->calculate($items, $taxMode, false);
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['items' => $e->getMessage()]);
        }

        return [$party, $items, $taxMode, $result, $date];
    }

    protected function nextNumber(string $kind, string $prefix): string
    {
        $last = Order::query()->ofKind($kind)->lockForUpdate()->pluck('number')
            ->map(fn ($n) => (int) preg_replace('/\D/', '', (string) $n))->max() ?? 0;

        return $prefix.'/'.str_pad((string) ($last + 1), 4, '0', STR_PAD_LEFT);
    }

    /** Saved order as save() input. */
    public function toInput(Order $order): array
    {
        $order->loadMissing('items');

        return [
            'date' => $order->date?->toDateString(),
            'delivery_date' => $order->delivery_date?->toDateString(),
            'party_account_id' => $order->party_account_id,
            'reference' => $order->reference,
            'reference_date' => $order->reference_date?->toDateString(),
            'place_of_supply' => $order->place_of_supply,
            'narration' => $order->narration,
            'terms' => $order->terms,
            'items' => $order->items->map(fn (OrderItem $i) => [
                'stock_item_id' => $i->stock_item_id,
                'description' => $i->description,
                'hsn_sac' => $i->hsn_sac,
                'quantity' => Decimal::trim($i->quantity),
                'unit' => $i->unit,
                'rate' => $i->rate,
                'discount_percent' => (float) $i->discount_percent ? Decimal::trim($i->discount_percent) : '',
                'gst_rate' => Decimal::trim($i->gst_rate),
            ])->all(),
        ];
    }
}
