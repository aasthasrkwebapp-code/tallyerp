<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Models\Godown;
use App\Models\StockGroup;
use App\Models\StockItem;
use App\Services\Inventory\StockLedger;
use App\Support\CsvExport;
use App\Support\Decimal;
use App\Support\Money;
use Illuminate\Http\Request;

class StockReportController extends Controller
{
    public function __construct(protected StockLedger $ledger) {}

    /** Closing quantity, rate and value of every item, grouped by stock group. */
    public function summary(Request $request)
    {
        $filters = $request->validate([
            'to' => ['nullable', 'date'],
            'godown' => ['nullable', 'integer'],
            'zero' => ['nullable', 'boolean'],
        ]);
        $asOf = $filters['to'] ?? now()->toDateString();
        $godownId = isset($filters['godown']) ? (int) $filters['godown'] : null;

        $items = StockItem::query()->with('unit', 'group')->orderBy('name')->get();
        $positions = $this->ledger->position($asOf, $items, $godownId)
            ->filter(fn ($p) => ($filters['zero'] ?? false) || $p->quantity !== 0 || $p->value !== 0);

        // Group rows in stock-group tree order; items without a group go last.
        $groups = StockGroup::tree();
        $byGroup = $positions->groupBy(fn ($p) => $p->item->stock_group_id ?? 0);
        $sections = $groups->map(fn ($g) => (object) ['group' => $g, 'rows' => $byGroup->get($g->id, collect())])
            ->push((object) ['group' => null, 'rows' => $byGroup->get(0, collect())])
            ->filter(fn ($s) => $s->rows->isNotEmpty())
            ->values();

        if (\App\Support\CsvExport::requested($request)) {
            $this->authorize('reports.export');

            return CsvExport::download("stock-summary-{$asOf}.csv", ['Item', 'Group', 'Quantity', 'Unit', 'Rate', 'Value'],
                $positions->map(fn ($p) => [
                    $p->item->name, $p->item->group?->name ?? '', Decimal::trim(Decimal::fromScaled($p->quantity, 3)), $p->item->unit->symbol,
                    Money::fromPaise($p->rate), Money::fromPaise($p->value),
                ])->values()->push(['Total', '', '', '', '', Money::fromPaise($positions->sum('value'))]));
        }

        return view('inventory.reports.summary', [
            'sections' => $sections,
            'total' => $positions->sum('value'),
            'asOf' => $asOf,
            'godownId' => $godownId,
            'godowns' => Godown::query()->orderBy('name')->get(),
            'filters' => $filters,
        ]);
    }

    /** Items at or below their reorder level, and items with negative stock. */
    public function reorder(Request $request)
    {
        $items = StockItem::query()->with('unit', 'group', 'purchaseAccount')->where('is_active', true)->orderBy('name')->get();
        $positions = $this->ledger->position(now()->toDateString(), $items);

        $rows = $positions->filter(function ($p) {
            $level = $p->item->reorder_level !== null ? Decimal::toScaled($p->item->reorder_level, 3) : null;

            return $p->quantity < 0 || ($level !== null && $p->quantity <= $level);
        })->map(function ($p) {
            $level = $p->item->reorder_level !== null ? Decimal::toScaled($p->item->reorder_level, 3) : 0;
            $p->level = $level;
            $p->shortfall = max($level - $p->quantity, 0);

            return $p;
        })->values();

        return view('inventory.reports.reorder', ['rows' => $rows]);
    }
}
