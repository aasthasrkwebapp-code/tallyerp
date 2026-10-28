<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Models\Godown;
use App\Models\StockGroup;
use App\Models\StockItem;
use App\Services\Inventory\BatchTracker;
use App\Services\Inventory\StockLedger;
use App\Support\CsvExport;
use App\Support\Decimal;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

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

    /** Age of the stock in hand by receipt date, in day slabs. */
    public function ageing(Request $request, \App\Services\Inventory\StockAgeing $ageing)
    {
        $filters = $request->validate([
            'to' => ['nullable', 'date'],
            'godown' => ['nullable', 'integer'],
            'group' => ['nullable', 'integer'],
            'slabs' => ['nullable', 'string', 'max:60'],
        ]);
        $slabs = \App\Services\Inventory\StockAgeing::parseSlabs($filters['slabs'] ?? null);
        if ($slabs === null) {
            return back()->withInput()->withErrors(['slabs' => 'Enter up to 8 ascending day limits separated by commas, such as 30,60,90,180.']);
        }
        $asOf = Carbon::parse($filters['to'] ?? now())->toDateString();
        $godownId = isset($filters['godown']) ? (int) $filters['godown'] : null;
        $items = StockItem::query()->with('unit', 'group')
            ->when(isset($filters['group']), fn ($q) => $q->whereIn('stock_group_id', StockGroup::descendantIdsOf([(int) $filters['group']])))
            ->orderBy('name')->get();
        $report = $ageing->report($asOf, $slabs, $godownId, $items);

        if (CsvExport::requested($request)) {
            $this->authorize('reports.export');
            $header = ['Item', 'Group', 'Unit', 'Quantity', 'Value'];
            foreach ($report['labels'] as $label) {
                array_push($header, $label.' qty', $label.' value');
            }
            $header[] = 'Oldest receipt';
            $rows = $report['rows']->map(function ($r) {
                $line = [$r->item->name, $r->item->group?->name ?? '', $r->item->unit->symbol, Decimal::trim(Decimal::fromScaled($r->quantity, 3)), Money::fromPaise($r->value)];
                foreach ($r->slabs as [$qty, $value]) {
                    array_push($line, Decimal::trim(Decimal::fromScaled($qty, 3)), Money::fromPaise($value));
                }
                $line[] = $r->oldest ?? '';

                return $line;
            });
            $total = ['Total', '', '', '', Money::fromPaise($report['total_value'])];
            foreach ($report['totals'] as $value) {
                array_push($total, '', Money::fromPaise($value));
            }
            $total[] = '';

            return CsvExport::download("stock-ageing-{$asOf}.csv", $header, $rows->push($total));
        }

        return view('inventory.reports.ageing', $report + [
            'asOf' => $asOf,
            'godownId' => $godownId,
            'godowns' => Godown::query()->orderBy('name')->get(),
            'groups' => StockGroup::tree(),
            'filters' => $filters,
            'slabText' => implode(',', $slabs),
        ]);
    }

    /** Batch-wise stock with expiry status. */
    public function batches(Request $request)
    {
        $filters = $request->validate([
            'to' => ['nullable', 'date'],
            'item' => ['nullable', 'integer'],
            'godown' => ['nullable', 'integer'],
            'status' => ['nullable', 'in:in_stock,expired,expiring,all'],
            'days' => ['nullable', 'integer', 'min:1', 'max:3650'],
        ]);
        $asOf = Carbon::parse($filters['to'] ?? now())->toDateString();
        $status = $filters['status'] ?? 'in_stock';
        $days = (int) ($filters['days'] ?? 30);
        $soon = Carbon::parse($asOf)->addDays($days)->toDateString();

        $items = StockItem::query()->with('unit')->where('tracking', 'batch')->orderBy('name')->get()->keyBy('id');
        $godowns = Godown::query()->orderBy('name')->get();
        $rows = app(BatchTracker::class)->batches($asOf, isset($filters['item']) ? [(int) $filters['item']] : $items->keys()->all())
            ->filter(fn ($b) => $items->has($b->stock_item_id))
            ->when(isset($filters['godown']), fn ($c) => $c->where('godown_id', (int) $filters['godown']))
            ->filter(fn ($b) => $status === 'all' || $b->quantity > 0)
            ->map(function ($b) use ($items, $asOf, $soon) {
                $b->item = $items[$b->stock_item_id];
                $b->state = ! $b->expiry_date ? 'none' : ($b->expiry_date < $asOf ? 'expired' : ($b->expiry_date <= $soon ? 'expiring' : 'ok'));
                $b->days_left = $b->expiry_date ? (int) Carbon::parse($asOf)->diffInDays(Carbon::parse($b->expiry_date), false) : null;

                return $b;
            })
            ->filter(fn ($b) => ! in_array($status, ['expired', 'expiring'], true) || $b->state === $status)
            ->sortBy(fn ($b) => $b->item->name.'|'.($b->expiry_date ?? '9999-99-99').'|'.$b->batch_no)->values();

        if (CsvExport::requested($request)) {
            $this->authorize('reports.export');
            $names = $godowns->pluck('name', 'id');

            return CsvExport::download("batch-stock-{$asOf}.csv", ['Item', 'Batch', 'Godown', 'Quantity', 'Unit', 'Mfg date', 'Expiry date', 'Days to expiry'],
                $rows->map(fn ($b) => [
                    $b->item->name, $b->batch_no, $names[$b->godown_id] ?? '', Decimal::trim(Decimal::fromScaled($b->quantity, 3)), $b->item->unit->symbol,
                    $b->mfg_date ?? '', $b->expiry_date ?? '', $b->days_left ?? '',
                ]));
        }

        return view('inventory.reports.batches', [
            'rows' => $rows, 'items' => $items, 'godowns' => $godowns, 'filters' => $filters,
            'asOf' => $asOf, 'status' => $status, 'days' => $days,
            'counts' => ['expired' => $rows->where('state', 'expired')->count(), 'expiring' => $rows->where('state', 'expiring')->count()],
        ]);
    }

    /** Serial numbers: where each one is, and the history of one serial. */
    public function serials(Request $request)
    {
        $filters = $request->validate([
            'item' => ['nullable', 'integer'],
            'status' => ['nullable', 'in:in_stock,out,all'],
            'q' => ['nullable', 'string', 'max:50'],
        ]);
        $status = $filters['status'] ?? 'in_stock';
        $search = mb_strtoupper(trim($filters['q'] ?? ''));
        $items = StockItem::query()->with('unit')->where('tracking', 'serial')->orderBy('name')->get()->keyBy('id');
        $ids = isset($filters['item']) ? [(int) $filters['item']] : $items->keys()->all();
        $tracker = app(BatchTracker::class);

        $rows = collect();
        foreach ($tracker->serialStates($ids) as $itemId => $serials) {
            foreach ($serials as $serial => $state) {
                if (! $items->has($itemId)) {
                    continue;
                }
                $rows->push((object) (['serial' => (string) $serial, 'item' => $items[$itemId]] + (array) $state));
            }
        }
        $rows = $rows
            ->filter(fn ($r) => $status === 'all' || ($status === 'in_stock') === $r->in_stock)
            ->filter(fn ($r) => $search === '' || str_contains($r->serial, $search))
            ->sortBy(fn ($r) => $r->item->name.'|'.$r->serial)->values();
        $history = $search !== '' ? $tracker->serialHistory($search, $ids) : collect();
        $godowns = Godown::query()->pluck('name', 'id');

        if (CsvExport::requested($request)) {
            $this->authorize('reports.export');

            return CsvExport::download('serial-numbers-'.now()->toDateString().'.csv', ['Item', 'Serial', 'Status', 'Godown', 'Last movement', 'Date', 'Document', 'Party'],
                $rows->map(fn ($r) => [
                    $r->item->name, $r->serial, $r->in_stock ? 'In stock' : 'Out', $r->in_stock ? ($godowns[$r->godown_id] ?? '') : '',
                    StockLedger::LABELS[$r->kind] ?? $r->kind, $r->kind === 'opening' ? '' : $r->date, $r->number ?? '', $r->party ?? '',
                ]));
        }

        return view('inventory.reports.serials', [
            'rows' => $rows, 'items' => $items, 'filters' => $filters, 'status' => $status, 'search' => $search,
            'history' => $history, 'godowns' => $godowns,
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
