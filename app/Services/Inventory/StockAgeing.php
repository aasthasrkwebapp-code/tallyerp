<?php

namespace App\Services\Inventory;

use App\Models\Godown;
use App\Models\StockItem;
use App\Support\CurrentCompany;
use App\Support\Decimal;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Stock ageing: how long the stock in hand has been held.
 *
 * Stock is assumed to go out oldest first, so the closing quantity is made up
 * of the most recent receipts. Walking the receipts from the newest back, each
 * one fills the closing quantity until it is used up, and its quantity falls in
 * the age slab of its receipt date. Opening stock is dated on the books
 * beginning date. Company-wide, godown transfers are not receipts; for one
 * godown, transfers into it are.
 *
 * Each slab's value is its share of the item's closing value (at the item's
 * costing method), so the slabs add up to the stock summary.
 */
class StockAgeing
{
    public const DEFAULT_SLABS = [30, 60, 90, 180];

    public function __construct(protected StockLedger $ledger) {}

    /**
     * @param  array<int, int>  $slabs  upper day limits of each slab, ascending; one more slab holds the rest
     * @return array{labels: array<int, string>, rows: Collection, totals: array<int, int> value per slab, total_value: int}
     *   rows: object{item, quantity, value, slabs: array<int, array{0:int qty, 1:int value}>, oldest: ?string, days: ?int}
     */
    public function report(string $asOf, array $slabs = self::DEFAULT_SLABS, ?int $godownId = null, ?Collection $items = null): array
    {
        $items ??= StockItem::query()->with('unit', 'group')->orderBy('name')->get();
        $positions = $this->ledger->position($asOf, $items, $godownId)->filter(fn ($p) => $p->quantity > 0);
        $movements = $this->ledger->movements($positions->keys()->all(), $asOf)->groupBy('stock_item_id');
        $begin = app(CurrentCompany::class)->get()?->books_begin_date?->toDateString() ?? $asOf;
        $defaultGodown = Godown::defaultId();
        $asOfDate = Carbon::parse($asOf);
        $slabCount = count($slabs) + 1;

        $rows = $positions->map(function ($p) use ($movements, $godownId, $defaultGodown, $begin, $asOfDate, $slabs, $slabCount) {
            $item = $p->item;
            // Receipts, newest last.
            $lots = [];
            $openingQty = Decimal::toScaled($item->opening_quantity, 3);
            if ($openingQty > 0 && (! $godownId || ($item->opening_godown_id ?? $defaultGodown) === $godownId)) {
                $lots[] = [$begin, $openingQty];
            }
            foreach ($movements->get($item->id, collect()) as $m) {
                if ($m->quantity <= 0) {
                    continue;
                }
                if ($godownId ? ($m->godown_id ?? $defaultGodown) !== $godownId : $m->kind === 'transfer_in') {
                    continue;
                }
                $lots[] = [$m->date, $m->quantity];
            }

            $byslab = array_fill(0, $slabCount, 0);
            $remaining = $p->quantity;
            $oldest = null;
            for ($i = count($lots) - 1; $i >= 0 && $remaining > 0; $i--) {
                [$date, $qty] = $lots[$i];
                $take = min($qty, $remaining);
                $byslab[$this->slab($asOfDate->diffInDays(Carbon::parse($date), true), $slabs)] += $take;
                $remaining -= $take;
                $oldest = $date;
            }
            // More in hand than the receipts explain (stock went negative earlier): treat it as oldest.
            $byslab[$slabCount - 1] += $remaining;

            // Values in proportion to quantity; the last non-empty slab takes the rounding.
            $out = [];
            $valueLeft = $p->value;
            $lastFilled = max(array_keys(array_filter($byslab)) ?: [0]);
            foreach ($byslab as $s => $qty) {
                $value = $s === $lastFilled ? $valueLeft : Decimal::mulDiv($p->value, $qty, $p->quantity);
                $valueLeft -= $s === $lastFilled ? $valueLeft : $value;
                $out[$s] = [$qty, $value];
            }

            return (object) [
                'item' => $item,
                'quantity' => $p->quantity,
                'value' => $p->value,
                'slabs' => $out,
                'oldest' => $remaining > 0 ? null : $oldest,
                'days' => $remaining > 0 || ! $oldest ? null : (int) $asOfDate->diffInDays(Carbon::parse($oldest), true),
            ];
        })->values();

        $totals = [];
        for ($s = 0; $s < $slabCount; $s++) {
            $totals[$s] = $rows->sum(fn ($r) => $r->slabs[$s][1]);
        }

        return ['labels' => self::labels($slabs), 'rows' => $rows, 'totals' => $totals, 'total_value' => $rows->sum('value')];
    }

    protected function slab(float $days, array $slabs): int
    {
        foreach ($slabs as $i => $limit) {
            if ($days <= $limit) {
                return $i;
            }
        }

        return count($slabs);
    }

    /** @return array<int, string> "0–30 days", "31–60 days", ..., "Over 180 days" */
    public static function labels(array $slabs): array
    {
        $labels = [];
        $from = 0;
        foreach ($slabs as $limit) {
            $labels[] = "{$from}–{$limit} days";
            $from = $limit + 1;
        }
        $labels[] = 'Over '.end($slabs).' days';

        return $labels;
    }

    /** "30,60,90" => [30, 60, 90], or null when not 1–8 ascending whole numbers up to 3650. */
    public static function parseSlabs(?string $value): ?array
    {
        if ($value === null || trim($value) === '') {
            return self::DEFAULT_SLABS;
        }
        $parts = array_map('trim', explode(',', $value));
        if (count($parts) > 8 || array_filter($parts, fn ($p) => ! ctype_digit($p)) !== []) {
            return null;
        }
        $slabs = array_map('intval', $parts);
        foreach ($slabs as $i => $d) {
            if ($d < 1 || $d > 3650 || ($i > 0 && $d <= $slabs[$i - 1])) {
                return null;
            }
        }

        return $slabs;
    }
}
