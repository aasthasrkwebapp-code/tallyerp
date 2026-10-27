<?php

namespace App\Services\Inventory;

use App\Models\AuditLog;
use App\Models\DeliveryNote;
use App\Models\DeliveryNoteItem;
use App\Models\Godown;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\StockItem;
use App\Models\User;
use App\Models\Voucher;
use App\Services\Billing\InvoiceService;
use App\Services\Billing\OrderService;
use App\Support\Decimal;
use App\Support\Money;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Delivery notes and goods receipt notes.
 *
 * A note moves stock when saved (out for a delivery note, in for a GRN) and
 * may fulfil sales / purchase order lines. The invoice or bill made later
 * against note lines does the accounting only. A note can be cancelled while
 * no posted invoice uses it.
 *
 * Input: date, party_account_id, reference, reference_date, narration,
 * lines: [stock_item_id, godown_id, quantity, rate, order_item_id, batch_no, mfg_date, expiry_date, serials]
 */
class DeliveryNoteService
{
    public function __construct(
        protected StockJournalService $journals,
        protected StockLedger $ledger,
        protected BatchTracker $batches,
        protected OrderService $orders,
    ) {}

    public function create(string $kind, array $data, User $user): DeliveryNote
    {
        $config = DeliveryNote::kindConfig($kind);
        $date = $this->journals->validDate($data['date'] ?? null);
        $party = app(InvoiceService::class)->partyQuery($config['invoice_kind'])->where('account_type', 'party')->find($data['party_account_id'] ?? 0);
        if (! $party) {
            throw ValidationException::withMessages(['party_account_id' => 'Select a '.($config['direction'] === 'out' ? 'customer' : 'supplier').'.']);
        }

        $lines = $this->lines($kind, $config, $data, $party->id, $date);

        return DB::transaction(function () use ($kind, $config, $data, $user, $party, $lines, $date) {
            $note = DeliveryNote::create([
                'kind' => $kind,
                'number' => $this->nextNumber($kind, $config['prefix']),
                'date' => $date,
                'party_account_id' => $party->id,
                'party_name' => $party->name,
                'reference' => isset($data['reference']) ? mb_substr(trim((string) $data['reference']), 0, 50) ?: null : null,
                'reference_date' => $data['reference_date'] ?? null,
                'status' => 'posted',
                'narration' => isset($data['narration']) ? mb_substr(trim((string) $data['narration']), 0, 2000) ?: null : null,
                'created_by' => $user->id,
            ]);
            foreach ($lines as $n => $line) {
                $note->items()->create($line + ['company_id' => $note->company_id, 'line_no' => $n + 1]);
            }

            return $note->load('items.item.unit', 'items.godown');
        });
    }

    public function cancel(DeliveryNote $note, User $user, string $reason): DeliveryNote
    {
        if (! $note->isPosted()) {
            throw ValidationException::withMessages(['note' => 'This note is already cancelled.']);
        }
        if ($this->invoiced($note->items->pluck('id')->all())) {
            throw ValidationException::withMessages(['note' => 'A posted invoice uses this note. Cancel the invoice first.']);
        }
        $year = $note->company->financialYearFor($note->date);
        if ($year && $year->isDateLocked($note->date)) {
            throw ValidationException::withMessages(['note' => 'The note is in a locked financial year or period and cannot be cancelled.']);
        }
        // Undoing a GRN must not take out serials or batches that have since gone out.
        if ($note->config()['direction'] === 'in') {
            $this->assertReceiptCanBeUndone($note);
        }

        $note->update(['status' => 'cancelled', 'cancellation_reason' => mb_substr($reason, 0, 255), 'cancelled_by' => $user->id, 'cancelled_at' => now()]);
        AuditLog::record('cancelled', $note, null, null, $note->config()['label']." {$note->number} cancelled: {$reason}");

        return $note;
    }

    /**
     * Quantity invoiced (posted) against each note line, in thousandths.
     *
     * @return array<int, int>
     */
    public function invoicedQuantities(array $noteItemIds, ?int $exceptInvoiceId = null): array
    {
        if (! $noteItemIds) {
            return [];
        }

        return DB::table('invoice_items as ii')
            ->join('invoices as i', 'i.id', '=', 'ii.invoice_id')
            ->join('vouchers as v', 'v.id', '=', 'i.voucher_id')
            ->where('v.status', Voucher::POSTED)
            ->whereIn('ii.note_item_id', $noteItemIds)
            ->when($exceptInvoiceId, fn ($q) => $q->where('i.id', '!=', $exceptInvoiceId))
            ->get(['ii.note_item_id', 'ii.quantity'])
            ->groupBy('note_item_id')
            ->map(fn ($rows) => $rows->sum(fn ($r) => Decimal::toScaled($r->quantity, 3)))
            ->all();
    }

    /** Lines of a note with quantity, invoiced and still to invoice. */
    public function progress(DeliveryNote $note): Collection
    {
        $note->loadMissing('items.item.unit', 'items.godown', 'items.orderItem.order');
        $done = $this->invoicedQuantities($note->items->pluck('id')->all());

        return $note->items->map(function (DeliveryNoteItem $line) use ($done, $note) {
            $qty = Decimal::toScaled($line->quantity, 3);
            $invoiced = $done[$line->id] ?? 0;

            return (object) ['line' => $line, 'quantity' => $qty, 'invoiced' => $invoiced, 'pending' => $note->isPosted() ? max(0, $qty - $invoiced) : 0];
        });
    }

    /** "Not invoiced", "Part invoiced", "Invoiced" or "Cancelled". */
    public function statusLabel(DeliveryNote $note, ?Collection $progress = null): string
    {
        if (! $note->isPosted()) {
            return 'Cancelled';
        }
        $progress ??= $this->progress($note);
        $word = $note->config()['invoice_kind'] === 'sales' ? 'invoiced' : 'billed';
        if ($progress->every(fn ($p) => $p->pending === 0)) {
            return ucfirst($word);
        }

        return $progress->contains(fn ($p) => $p->invoiced > 0) ? 'Part '.$word : 'Not '.$word;
    }

    /** Note form input for the pending lines of an open order. */
    public function orderInput(Order $order): array
    {
        $godown = Godown::defaultId();
        $lines = $this->orders->progress($order)->filter(fn ($p) => $p->pending > 0 && $p->item->stock_item_id)->map(fn ($p) => [
            'order_item_id' => $p->item->id,
            'stock_item_id' => $p->item->stock_item_id,
            'godown_id' => $godown,
            'quantity' => Decimal::trim(Decimal::fromScaled($p->pending, 3)),
            'rate' => $p->item->rate,
        ])->values()->all();
        if (! $order->isOpen() || $lines === []) {
            throw ValidationException::withMessages(['order' => 'This order has no pending stock items to deliver.']);
        }

        return ['party_account_id' => $order->party_account_id, 'reference' => $order->reference, 'lines' => $lines];
    }

    /** Invoice form input for the lines of one or more notes still to be invoiced. */
    public function invoiceInput(DeliveryNote $note): array
    {
        $config = $note->config();
        $ledgerColumn = $config['invoice_kind'] === 'sales' ? 'sales_account_id' : 'purchase_account_id';
        $items = $this->progress($note)->filter(fn ($p) => $p->pending > 0)->map(function ($p) use ($ledgerColumn) {
            $line = $p->line;
            $order = $line->orderItem;

            return [
                'note_item_id' => $line->id,
                'stock_item_id' => $line->stock_item_id,
                'godown_id' => $line->godown_id,
                'account_id' => $line->item->{$ledgerColumn},
                'description' => $order?->description ?? $line->description,
                'hsn_sac' => $order?->hsn_sac ?? $line->item->hsn_sac,
                'quantity' => Decimal::trim(Decimal::fromScaled($p->pending, 3)),
                'unit' => $line->unit,
                'rate' => $order?->rate ?? ((float) $line->rate ? $line->rate : ($ledgerColumn === 'sales_account_id' ? $line->item->sales_rate : $line->item->purchase_rate)),
                'discount_percent' => $order?->discount_percent,
                'gst_rate' => $order?->gst_rate ?? $line->item->gst_rate,
                'batch_no' => $line->batch_no,
                'serials' => $line->serials,
            ];
        })->values()->all();
        if (! $note->isPosted() || $items === []) {
            throw ValidationException::withMessages(['note' => 'Everything on this note has been invoiced.']);
        }

        return [
            'party_account_id' => $note->party_account_id,
            'reference' => $config['invoice_kind'] === 'purchase' ? null : $note->items->first()?->orderItem?->order?->reference,
            'narration' => 'Against '.mb_strtolower($config['label']).' '.$note->number,
            'items' => $items,
        ];
    }

    /**
     * Check invoice lines made against note lines.
     *
     * @param  array<int, array{key:string, label:string, note_item_id:int, stock_item_id:?int, quantity:string}>  $lines
     * @return array{0: array<string,string>, 1: array<int, DeliveryNoteItem>} errors and the note line of each input line
     */
    public function checkInvoiceLines(string $invoiceKind, int $partyId, array $lines, ?Invoice $invoice): array
    {
        $noteKind = collect(DeliveryNote::kinds())->search(fn ($c) => $c['invoice_kind'] === $invoiceKind);
        $errors = [];
        $found = [];
        if (! $noteKind) {
            foreach ($lines as $l) {
                $errors[$l['key'].'.note_item_id'] = "{$l['label']}: only sales invoices and purchase bills can be made against delivery or receipt notes.";
            }

            return [$errors, []];
        }
        $noteItems = DeliveryNoteItem::query()->with('note')->whereIn('id', array_column($lines, 'note_item_id'))->get()->keyBy('id');
        $done = $this->invoicedQuantities($noteItems->keys()->all(), $invoice?->id);
        $used = [];
        foreach ($lines as $n => $l) {
            $ni = $noteItems->get($l['note_item_id']);
            $key = $l['key'].'.note_item_id';
            if (! $ni || $ni->note->kind !== $noteKind || $ni->note->party_account_id !== $partyId) {
                $errors[$key] = "{$l['label']}: the note line does not belong to a ".mb_strtolower(DeliveryNote::kindConfig($noteKind)['label']).' of this party.';

                continue;
            }
            if (! $ni->note->isPosted()) {
                $errors[$key] = "{$l['label']}: note {$ni->note->number} is cancelled.";

                continue;
            }
            if ((int) ($l['stock_item_id'] ?? 0) !== $ni->stock_item_id) {
                $errors[$key] = "{$l['label']}: the item differs from line {$ni->line_no} of note {$ni->note->number}.";

                continue;
            }
            $qty = Decimal::toScaled((string) $l['quantity'], 3);
            $pending = Decimal::toScaled($ni->quantity, 3) - ($done[$ni->id] ?? 0) - ($used[$ni->id] ?? 0);
            if ($qty > $pending) {
                $errors[$l['key'].'.quantity'] = "{$l['label']}: only ".Decimal::trim(Decimal::fromScaled(max(0, $pending), 3))." is still to be invoiced on note {$ni->note->number}.";

                continue;
            }
            $used[$ni->id] = ($used[$ni->id] ?? 0) + $qty;
            $found[$n] = $ni;
        }

        return [$errors, $found];
    }

    /** Lines of posted notes not yet (fully) invoiced, oldest first. */
    public function pending(string $kind, ?int $partyId = null): Collection
    {
        $notes = DeliveryNote::query()->ofKind($kind)->where('status', 'posted')
            ->when($partyId, fn ($q) => $q->where('party_account_id', $partyId))
            ->with('items.item.unit')->orderBy('date')->orderBy('id')->get();
        $done = $this->invoicedQuantities($notes->flatMap->items->pluck('id')->all());

        return $notes->flatMap(fn (DeliveryNote $n) => $n->items->map(function (DeliveryNoteItem $line) use ($n, $done) {
            $qty = Decimal::toScaled($line->quantity, 3);
            $invoiced = $done[$line->id] ?? 0;
            $pending = max(0, $qty - $invoiced);
            $rate = (float) $line->rate ? Money::toPaise($line->rate) : 0;

            return (object) ['note' => $n, 'line' => $line, 'quantity' => $qty, 'invoiced' => $invoiced, 'pending' => $pending,
                'value' => Decimal::mulDiv($pending, $rate, 1000)];
        }))->filter(fn ($r) => $r->pending > 0)->values();
    }

    /** Posted invoices against a note. */
    public function invoicesFor(DeliveryNote $note): Collection
    {
        return Invoice::query()->whereHas('items', fn ($q) => $q->whereIn('note_item_id', $note->items->pluck('id')))
            ->with('voucher')->orderBy('date')->get();
    }

    // ------------------------------------------------------------------

    protected function invoiced(array $noteItemIds): bool
    {
        return collect($this->invoicedQuantities($noteItemIds))->filter()->isNotEmpty();
    }

    protected function lines(string $kind, array $config, array $data, int $partyId, string $date): array
    {
        $input = array_values($data['lines'] ?? []);
        $items = StockItem::query()->with('unit')->whereIn('id', array_filter(array_map(fn ($l) => (int) ($l['stock_item_id'] ?? 0), $input)))->get()->keyBy('id');
        $godowns = Godown::query()->where('is_active', true)->pluck('id')->all();
        $orderItems = OrderItem::query()->with('order')->whereIn('id', array_filter(array_map(fn ($l) => (int) ($l['order_item_id'] ?? 0), $input)))->get()->keyBy('id');
        $delivered = $this->orders->delivered($orderItems->keys()->all());
        $usedOrder = [];
        $errors = [];
        $rows = [];

        foreach ($input as $i => $line) {
            if (empty($line['stock_item_id']) && trim((string) ($line['quantity'] ?? '')) === '') {
                continue;
            }
            $label = 'Line '.(count($rows) + 1);
            $item = $items->get((int) ($line['stock_item_id'] ?? 0));
            if (! $item || ! $item->is_active) {
                $errors["lines.$i.stock_item_id"] = "{$label}: choose an active stock item.";

                continue;
            }
            $quantity = str_replace(',', '', trim((string) ($line['quantity'] ?? '')));
            if (! preg_match('/^\d{1,7}(\.\d{1,3})?$/', $quantity) || (float) $quantity <= 0) {
                $errors["lines.$i.quantity"] = "{$label}: enter a quantity greater than zero.";

                continue;
            }
            if (str_contains($quantity, '.') && strlen(rtrim(explode('.', $quantity)[1], '0')) > $item->unit->decimal_places) {
                $errors["lines.$i.quantity"] = "{$label}: unit {$item->unit->symbol} allows {$item->unit->decimal_places} decimal places.";

                continue;
            }
            $godownId = (int) ($line['godown_id'] ?? 0) ?: Godown::defaultId();
            if (! in_array($godownId, $godowns, true)) {
                $errors["lines.$i.godown_id"] = "{$label}: choose a godown.";
            }
            $rate = str_replace(',', '', trim((string) ($line['rate'] ?? '')));
            if ($rate !== '' && ! preg_match('/^\d{1,11}(\.\d{1,2})?$/', $rate)) {
                $errors["lines.$i.rate"] = "{$label}: enter a valid rate.";
            }

            $orderItemId = (int) ($line['order_item_id'] ?? 0) ?: null;
            if ($orderItemId) {
                $oi = $orderItems->get($orderItemId);
                $qty = Decimal::toScaled($quantity, 3);
                if (! $oi || $oi->order->kind !== $config['order_kind'] || $oi->order->party_account_id !== $partyId || $oi->stock_item_id !== $item->id) {
                    $errors["lines.$i.order_item_id"] = "{$label}: the order line does not match this party and item.";
                } elseif (! $oi->order->isOpen()) {
                    $errors["lines.$i.order_item_id"] = "{$label}: order {$oi->order->number} is {$oi->order->status}.";
                } elseif ($qty > ($pending = Decimal::toScaled($oi->quantity, 3) - ($delivered[$oi->id] ?? 0) - ($usedOrder[$oi->id] ?? 0))) {
                    $errors["lines.$i.quantity"] = "{$label}: only ".Decimal::trim(Decimal::fromScaled(max(0, $pending), 3))." is pending on order {$oi->order->number}.";
                } else {
                    $usedOrder[$oi->id] = ($usedOrder[$oi->id] ?? 0) + $qty;
                }
                $rate = $rate !== '' ? $rate : (string) ($oi?->rate ?? '');
            }
            if ($rate === '') {
                $rate = (string) (($config['direction'] === 'in' ? $item->purchase_rate : $item->sales_rate) ?? '0');
            }

            $rows[] = [
                'item' => $item, 'key' => "lines.$i", 'label' => $label, 'godown_id' => $godownId, 'direction' => $config['direction'],
                'quantity' => $quantity, 'rate' => $rate, 'order_item_id' => $orderItemId,
                'batch_no' => $line['batch_no'] ?? null, 'mfg_date' => $line['mfg_date'] ?? null,
                'expiry_date' => $line['expiry_date'] ?? null, 'serials' => $line['serials'] ?? null,
            ];
        }
        if ($rows === [] && ! $errors) {
            $errors['lines'] = 'Add at least one item.';
        }
        if (count($rows) > 200) {
            $errors['lines'] = 'A note can have at most 200 lines.';
        }
        if (! $errors) {
            [$fields, $trackErrors] = $this->batches->check($rows, $date);
            $errors += $trackErrors;
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        return array_map(fn ($r, $n) => [
            'stock_item_id' => $r['item']->id, 'godown_id' => $r['godown_id'], 'order_item_id' => $r['order_item_id'],
            'description' => $r['item']->name, 'quantity' => $r['quantity'], 'unit' => $r['item']->unit->symbol,
            'rate' => Money::normalize($r['rate']),
            'value' => Money::fromPaise(Decimal::mulDiv(Decimal::toScaled($r['quantity'], 3), Money::toPaise($r['rate']), 1000)),
        ] + $fields[$n], $rows, array_keys($rows));
    }

    /** Serials and batches received on this GRN must still be in stock to cancel it. */
    protected function assertReceiptCanBeUndone(DeliveryNote $note): void
    {
        $note->loadMissing('items.item.unit');
        $tracked = $note->items->filter(fn ($l) => $l->item->tracking !== 'none');
        if ($tracked->isEmpty()) {
            return;
        }
        $lines = $tracked->values()->map(fn ($l) => [
            'key' => 'note', 'label' => 'Line '.$l->line_no, 'item' => $l->item, 'godown_id' => $l->godown_id, 'direction' => 'out',
            'quantity' => Decimal::trim($l->quantity), 'batch_no' => $l->batch_no, 'serials' => $l->serials,
        ])->all();
        [, $errors] = $this->batches->check($lines, now()->toDateString());
        if ($errors) {
            throw ValidationException::withMessages(['note' => 'Some goods on this note have already gone out ('.collect($errors)->first().'). Reverse those documents first.']);
        }
    }

    protected function nextNumber(string $kind, string $prefix): string
    {
        $last = DeliveryNote::query()->ofKind($kind)->lockForUpdate()->pluck('number')
            ->map(fn ($n) => (int) preg_replace('/\D/', '', (string) $n))->max() ?? 0;

        return $prefix.'/'.str_pad((string) ($last + 1), 4, '0', STR_PAD_LEFT);
    }
}
