<?php

namespace App\Support;

use App\Models\Company;
use App\Models\StockItem;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Stock quantities and values, valued at weighted average cost of
 * opening stock plus all inwards up to the date.
 */
class Stock
{
    public function __construct(public readonly Company $company)
    {
    }

    /**
     * One row per stock item: opening, inwards, outwards and closing quantity and value.
     *
     * @return Collection<int, object>
     */
    public function summary(CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        $items = $this->company->stockItems()->with(['unit', 'group'])->orderBy('name')->get();

        $movements = $this->company->stockMovements()
            ->whereNull('stock_transfer_id') // moves between godowns don't change the company's stock
            ->whereDate('date', '<=', $to->toDateString())
            ->get()
            ->groupBy('stock_item_id');

        return $items->map(function (StockItem $item) use ($movements, $from) {
            $rows = $movements->get($item->id, collect());
            [$before, $during] = $rows->partition(fn ($m) => $m->date->lt($from));

            $inQty = (float) $item->opening_quantity + $rows->sum(fn ($m) => (float) $m->quantity_in);
            $inValue = $item->openingValue() + $rows->sum(fn ($m) => $m->quantity_in > 0 ? (float) $m->amount : 0);
            $average = $inQty > 0 ? $inValue / $inQty : (float) $item->opening_rate;

            $openingQty = (float) $item->opening_quantity
                + $before->sum(fn ($m) => (float) $m->quantity_in - (float) $m->quantity_out);
            $inwards = $during->sum(fn ($m) => (float) $m->quantity_in);
            $outwards = $during->sum(fn ($m) => (float) $m->quantity_out);
            $closingQty = round($openingQty + $inwards - $outwards, 3);

            return (object) [
                'item' => $item,
                'opening_qty' => round($openingQty, 3),
                'inwards' => round($inwards, 3),
                'inward_value' => round($during->sum(fn ($m) => $m->quantity_in > 0 ? (float) $m->amount : 0), 2),
                'outwards' => round($outwards, 3),
                'outward_value' => round($during->sum(fn ($m) => $m->quantity_out > 0 ? (float) $m->amount : 0), 2),
                'closing_qty' => $closingQty,
                'rate' => round($average, 2),
                'closing_value' => round($closingQty * $average, 2),
            ];
        });
    }

    public function openingValue(): float
    {
        return round($this->company->stockItems()->get()->sum(fn (StockItem $i) => $i->openingValue()), 2);
    }

    public function closingValue(CarbonImmutable $to): float
    {
        return round($this->summary($to, $to)->sum('closing_value'), 2);
    }
}
