<?php

namespace App\Services\Inventory;

use App\Models\StockItem;
use App\Models\Voucher;
use App\Support\Decimal;
use App\Support\Money;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Stock quantities and values derived from posted documents.
 *
 * Movements come from items of posted invoices (sales out, purchases in,
 * sales returns in, purchase returns out), lines of posted stock journals and
 * lines of posted delivery notes (out) and goods receipt notes (in). Invoice
 * items made against a note line do not move stock again.
 * Quantities are integers in thousandths, values integers in paise.
 *
 * Each item is valued by its costing method. Weighted average cost:
 * (opening value + purchases − purchase returns + goods receipt notes + valued
 * stock-journal inwards) ÷ the same quantities, all up to the valuation date,
 * applied to the closing quantity; sales returns and godown transfers move
 * quantity without changing the average. FIFO: the closing quantity is valued
 * at the cost of the most recent receipts still in hand (see fifoLayers()).
 */
class StockLedger
{
    /** Kinds that bring stock in. */
    public const INWARD = ['purchase', 'sales_return', 'journal_in', 'transfer_in', 'receipt'];

    public const LABELS = [
        'opening' => 'Opening stock',
        'purchase' => 'Purchase',
        'sales' => 'Sales',
        'sales_return' => 'Sales return',
        'purchase_return' => 'Purchase return',
        'journal_in' => 'Stock journal (in)',
        'journal_out' => 'Stock journal (out)',
        'transfer_in' => 'Transfer in',
        'transfer_out' => 'Transfer out',
        'delivery' => 'Delivery note',
        'receipt' => 'Goods receipt note',
    ];

    /**
     * Movement rows, oldest first.
     *
     * @return Collection<int, object{stock_item_id:int, godown_id:?int, date:string, kind:string, quantity:int, value:int, doc_type:string, doc_id:int, number:?string}>
     *   Also batch_no, mfg_date, expiry_date and serials (list).
 *   quantity is signed (+ in, − out); value is the cost-relevant value (purchases, purchase returns, valued journal inwards), signed like quantity.
     */
    public function movements(?array $itemIds = null, ?string $to = null, ?string $from = null, ?int $godownId = null): Collection
    {
        $invoices = DB::table('invoice_items as ii')
            ->join('invoices as i', 'i.id', '=', 'ii.invoice_id')
            ->join('vouchers as v', 'v.id', '=', 'i.voucher_id')
            ->where('v.status', Voucher::POSTED)
            ->whereNotNull('ii.stock_item_id')
            ->whereNull('ii.note_item_id') // the delivery / receipt note moved this stock
            ->where('i.company_id', app(\App\Support\CurrentCompany::class)->id())
            ->when($itemIds !== null, fn ($q) => $q->whereIn('ii.stock_item_id', $itemIds))
            ->when($to, fn ($q) => $q->where('i.date', '<=', $to))
            ->when($from, fn ($q) => $q->where('i.date', '>=', $from))
            ->when($godownId, fn ($q) => $q->where('ii.godown_id', $godownId))
            ->get(['ii.stock_item_id', 'ii.godown_id', 'i.date', 'i.kind', 'ii.quantity', 'ii.taxable_amount', 'i.id as doc_id', 'v.voucher_number as number', 'i.party_name', 'ii.id as line_id', 'ii.batch_no', 'ii.mfg_date', 'ii.expiry_date', 'ii.serials'])
            ->map(function ($r) {
                $qty = Decimal::toScaled($r->quantity, 3);
                [$kind, $sign, $valued] = match ($r->kind) {
                    'sales' => ['sales', -1, false],
                    'purchase' => ['purchase', 1, true],
                    'credit_note' => ['sales_return', 1, false],
                    'debit_note' => ['purchase_return', -1, true],
                };

                return (object) ([
                    'stock_item_id' => (int) $r->stock_item_id, 'godown_id' => $r->godown_id ? (int) $r->godown_id : null,
                    'date' => substr((string) $r->date, 0, 10), 'kind' => $kind, 'quantity' => $sign * $qty,
                    'value' => $valued ? $sign * Money::toPaise($r->taxable_amount) : 0,
                    'doc_type' => 'invoice', 'doc_id' => (int) $r->doc_id, 'number' => $r->number, 'party' => $r->party_name, 'line_id' => (int) $r->line_id,
                ] + self::tracking($r));
            });

        $journals = DB::table('stock_journal_lines as l')
            ->join('stock_journals as j', 'j.id', '=', 'l.stock_journal_id')
            ->where('j.status', 'posted')
            ->where('j.company_id', app(\App\Support\CurrentCompany::class)->id())
            ->when($itemIds !== null, fn ($q) => $q->whereIn('l.stock_item_id', $itemIds))
            ->when($to, fn ($q) => $q->where('j.date', '<=', $to))
            ->when($from, fn ($q) => $q->where('j.date', '>=', $from))
            ->when($godownId, fn ($q) => $q->where('l.godown_id', $godownId))
            ->get(['l.stock_item_id', 'l.godown_id', 'j.date', 'j.purpose', 'l.direction', 'l.quantity', 'l.value', 'j.id as doc_id', 'j.number', 'l.id as line_id', 'l.batch_no', 'l.mfg_date', 'l.expiry_date', 'l.serials'])
            ->map(function ($r) {
                $in = $r->direction === 'in';
                $transfer = $r->purpose === 'transfer';

                return (object) ([
                    'stock_item_id' => (int) $r->stock_item_id, 'godown_id' => (int) $r->godown_id,
                    'date' => substr((string) $r->date, 0, 10),
                    'kind' => $transfer ? ($in ? 'transfer_in' : 'transfer_out') : ($in ? 'journal_in' : 'journal_out'),
                    'quantity' => ($in ? 1 : -1) * Decimal::toScaled($r->quantity, 3),
                    'value' => $in && ! $transfer ? Money::toPaise($r->value) : 0,
                    'doc_type' => 'stock_journal', 'doc_id' => (int) $r->doc_id, 'number' => $r->number, 'party' => null, 'line_id' => (int) $r->line_id,
                ] + self::tracking($r));
            });

        $notes = DB::table('delivery_note_items as l')
            ->join('delivery_notes as n', 'n.id', '=', 'l.delivery_note_id')
            ->where('n.status', 'posted')
            ->where('n.company_id', app(\App\Support\CurrentCompany::class)->id())
            ->when($itemIds !== null, fn ($q) => $q->whereIn('l.stock_item_id', $itemIds))
            ->when($to, fn ($q) => $q->where('n.date', '<=', $to))
            ->when($from, fn ($q) => $q->where('n.date', '>=', $from))
            ->when($godownId, fn ($q) => $q->where('l.godown_id', $godownId))
            ->get(['l.stock_item_id', 'l.godown_id', 'n.date', 'n.kind', 'l.quantity', 'l.value', 'n.id as doc_id', 'n.number', 'n.party_name', 'l.id as line_id', 'l.batch_no', 'l.mfg_date', 'l.expiry_date', 'l.serials'])
            ->map(function ($r) {
                $in = $r->kind === 'receipt_note';

                return (object) ([
                    'stock_item_id' => (int) $r->stock_item_id, 'godown_id' => (int) $r->godown_id,
                    'date' => substr((string) $r->date, 0, 10), 'kind' => $in ? 'receipt' : 'delivery',
                    'quantity' => ($in ? 1 : -1) * Decimal::toScaled($r->quantity, 3),
                    'value' => $in ? Money::toPaise($r->value) : 0,
                    'doc_type' => 'delivery_note', 'doc_id' => (int) $r->doc_id, 'number' => $r->number, 'party' => $r->party_name, 'line_id' => (int) $r->line_id,
                ] + self::tracking($r));
            });

        return $invoices->concat($journals)->concat($notes)
            ->sortBy(fn ($m) => $m->date.'|'.self::rank($m->kind).'|'.str_pad((string) $m->line_id, 12, '0', STR_PAD_LEFT))
            ->values();
    }

    /**
     * Order of movements on the same day: receipts first, then transfers
     * (each transfer's out line before its in line), then issues.
     */
    protected static function rank(string $kind): string
    {
        return match (true) {
            in_array($kind, ['transfer_in', 'transfer_out'], true) => '1',
            in_array($kind, self::INWARD, true) => '0',
            default => '2',
        };
    }

    /** Batch and serial fields of a movement row. */
    protected static function tracking(object $r): array
    {
        return [
            'batch_no' => $r->batch_no,
            'mfg_date' => $r->mfg_date ? substr((string) $r->mfg_date, 0, 10) : null,
            'expiry_date' => $r->expiry_date ? substr((string) $r->expiry_date, 0, 10) : null,
            'serials' => BatchTracker::splitSerials($r->serials),
        ];
    }

    /**
     * Closing quantity, rate and value of each item as on a date, by the item's costing method.
     *
     * @return Collection<int, object{item: StockItem, quantity:int, value:int, rate:int, inward_qty:int, inward_value:int}> keyed by item id
     */
    public function position(?string $asOf = null, ?Collection $items = null, ?int $godownId = null): Collection
    {
        $items ??= StockItem::query()->with('unit', 'group')->orderBy('name')->get();
        $movements = $this->movements($items->pluck('id')->all(), $asOf)->groupBy('stock_item_id');
        $defaultGodown = \App\Models\Godown::defaultId();
        // Rows without a godown (and openings without one) belong to the default godown.
        $inGodown = fn (?int $id) => ($id ?? $defaultGodown) === $godownId;

        return $items->mapWithKeys(function (StockItem $item) use ($movements, $godownId, $inGodown) {
            $openingQty = Decimal::toScaled($item->opening_quantity, 3);
            $openingValue = Money::toPaise($item->opening_value);
            $rows = $movements->get($item->id, collect());

            // Cost is company-wide; quantities can be limited to a godown.
            $inQty = $openingQty + $rows->filter(fn ($m) => in_array($m->kind, ['purchase', 'purchase_return', 'journal_in', 'receipt'], true))->sum('quantity');
            $inValue = $openingValue + $rows->sum('value');
            $totalQty = $openingQty + $rows->sum('quantity');

            $qty = $godownId
                ? ($inGodown($item->opening_godown_id) ? $openingQty : 0) + $rows->filter(fn ($m) => $inGodown($m->godown_id))->sum('quantity')
                : $totalQty;

            if ($item->costing_method === 'fifo') {
                $fifo = $this->fifoLayers($item, $rows);
                $rate = $totalQty > 0 ? Decimal::mulDiv($fifo['value'], 1000, $totalQty) : $fifo['last_rate'];
                $value = $totalQty > 0 ? Decimal::mulDiv($qty, $fifo['value'], $totalQty) : Decimal::mulDiv($qty, $rate, 1000);
            } else {
                $rate = $inQty > 0 ? Decimal::mulDiv($inValue, 1000, $inQty) : Money::toPaise($item->purchase_rate);
                $value = $inQty > 0 ? Decimal::mulDiv($qty, $inValue, $inQty) : Decimal::mulDiv($qty, $rate, 1000);
            }

            return [$item->id => (object) [
                'item' => $item,
                'quantity' => $qty,
                'rate' => $rate, // paise per unit
                'value' => $value,
                'inward_qty' => $inQty,
                'inward_value' => $inValue,
            ]];
        });
    }

    /**
     * Cost in paise of issuing $quantity (thousandths) of an item on a date: the oldest
     * remaining lots for FIFO, the average cost otherwise.
     */
    public function issueCost(StockItem $item, int $quantity, string $date): int
    {
        if ($item->costing_method !== 'fifo') {
            $p = $this->position($date, collect([$item]))[$item->id];

            return Decimal::mulDiv($quantity, $p->rate, 1000);
        }
        $fifo = $this->fifoLayers($item, $this->movements([$item->id], $date));
        $cost = 0;
        foreach ($fifo['layers'] as [$lotQty, $lotValue]) {
            if ($quantity <= 0) {
                break;
            }
            $take = min($quantity, $lotQty);
            $cost += Decimal::mulDiv($lotValue, $take, $lotQty);
            $quantity -= $take;
        }

        return $cost + Decimal::mulDiv($quantity, $fifo['last_rate'], 1000); // beyond stock in hand
    }

    /**
     * FIFO lots left after the movements: [[quantity, value], ...] oldest first.
     *
     * Valued receipts (opening, purchases, receipt notes, valued journal inwards) add a lot at
     * their cost. Sales returns and unvalued inwards come back at the cost of the last issue
     * (the opening cost before any issue).
     * Issues and purchase returns use up the oldest lots first. Godown transfers are ignored:
     * they do not change the company's stock. Stock issued beyond what is in hand is a shortfall
     * that the next receipt fills first.
     *
     * @return array{layers: array<int, array{0:int, 1:int}>, value:int, last_rate:int}
     */
    public function fifoLayers(StockItem $item, Collection $rows): array
    {
        $layers = [];
        $shortfall = 0;
        $lastRate = Money::toPaise($item->purchase_rate); // latest cost seen
        $openingQty = Decimal::toScaled($item->opening_quantity, 3);
        if ($openingQty > 0) {
            $layers[] = [$openingQty, Money::toPaise($item->opening_value)];
            $lastRate = Decimal::mulDiv(Money::toPaise($item->opening_value), 1000, $openingQty);
        }
        $issueRate = $lastRate; // cost of the latest issue

        foreach ($rows as $m) {
            if (in_array($m->kind, ['transfer_in', 'transfer_out'], true) || $m->quantity === 0) {
                continue;
            }
            if ($m->quantity > 0) {
                $qty = $m->quantity;
                $value = $m->value > 0 ? $m->value : Decimal::mulDiv($qty, $issueRate, 1000);
                if ($m->value > 0) {
                    $lastRate = Decimal::mulDiv($m->value, 1000, $qty);
                }
                if ($shortfall > 0) {
                    $fill = min($shortfall, $qty);
                    $value -= Decimal::mulDiv($value, $fill, $qty);
                    $qty -= $fill;
                    $shortfall -= $fill;
                }
                if ($qty > 0) {
                    $layers[] = [$qty, $value];
                }

                continue;
            }

            $need = -$m->quantity;
            while ($need > 0 && $layers) {
                [$lotQty, $lotValue] = $layers[0];
                $take = min($need, $lotQty);
                $lastRate = $issueRate = Decimal::mulDiv($lotValue, 1000, $lotQty);
                if ($take === $lotQty) {
                    array_shift($layers);
                } else {
                    $layers[0] = [$lotQty - $take, $lotValue - Decimal::mulDiv($lotValue, $take, $lotQty)];
                }
                $need -= $take;
            }
            $shortfall += $need;
        }

        $value = array_sum(array_column($layers, 1)) - Decimal::mulDiv($shortfall, $lastRate, 1000);

        return ['layers' => $layers, 'value' => $value, 'last_rate' => $lastRate];
    }

    /**
     * Movements of one item in a period with a running quantity.
     *
     * @return array{opening:int, rows: Collection, inward:int, outward:int, closing:int}
     */
    public function itemLedger(StockItem $item, string $from, string $to, ?int $godownId = null): array
    {
        $defaultGodown = \App\Models\Godown::defaultId();
        $openingQty = Decimal::toScaled($item->opening_quantity, 3);
        if ($godownId && ($item->opening_godown_id ?? $defaultGodown) !== $godownId) {
            $openingQty = 0;
        }

        $all = $this->movements([$item->id], $to)
            ->when($godownId, fn ($c) => $c->filter(fn ($m) => ($m->godown_id ?? $defaultGodown) === $godownId));
        $before = $all->filter(fn ($m) => $m->date < $from);
        $balance = $openingQty + $before->sum('quantity');
        $opening = $balance;

        $rows = $all->filter(fn ($m) => $m->date >= $from)->values()->map(function ($m) use (&$balance) {
            $balance += $m->quantity;
            $m->balance = $balance;

            return $m;
        });

        return [
            'opening' => $opening,
            'rows' => $rows,
            'inward' => $rows->filter(fn ($m) => $m->quantity > 0)->sum('quantity'),
            'outward' => -$rows->filter(fn ($m) => $m->quantity < 0)->sum('quantity'),
            'closing' => $balance,
        ];
    }

    /** Quantity of each item held in each godown as on a date: [item id => [godown id => qty]]. */
    public function godownQuantities(?string $asOf = null, ?array $itemIds = null): array
    {
        $defaultGodown = \App\Models\Godown::defaultId();
        $out = [];
        $items = StockItem::query()->when($itemIds !== null, fn ($q) => $q->whereIn('id', $itemIds))->get(['id', 'opening_quantity', 'opening_godown_id']);
        foreach ($items as $item) {
            $qty = Decimal::toScaled($item->opening_quantity, 3);
            if ($qty) {
                $g = $item->opening_godown_id ?? $defaultGodown;
                $out[$item->id][$g] = ($out[$item->id][$g] ?? 0) + $qty;
            }
        }
        foreach ($this->movements($items->pluck('id')->all(), $asOf) as $m) {
            $g = $m->godown_id ?? $defaultGodown;
            $out[$m->stock_item_id][$g] = ($out[$m->stock_item_id][$g] ?? 0) + $m->quantity;
        }

        return $out;
    }

    /** Total closing stock value of all items as on a date (end of day). */
    public function valueAt(string $asOf): int
    {
        return $this->position($asOf)->sum('value');
    }

    /** Value of the opening stock entered on the items (as at books beginning). */
    public function openingValue(): int
    {
        return StockItem::query()->get(['opening_value'])->sum(fn ($i) => Money::toPaise($i->opening_value));
    }

    /** True when any stock item exists, i.e. stock affects the statements. */
    public function active(): bool
    {
        return StockItem::query()->exists();
    }

    /** Quantity as display text with the unit's decimals, e.g. "12.5 Kg". */
    public static function qty(int $milli, ?\App\Models\Unit $unit = null): string
    {
        return Decimal::trim(Decimal::fromScaled($milli, 3)).($unit ? ' '.$unit->symbol : '');
    }
}
