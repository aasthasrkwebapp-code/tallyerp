<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Http\Requests\StockItemRequest;
use App\Models\Account;
use App\Models\Godown;
use App\Models\StockCategory;
use App\Models\StockGroup;
use App\Models\StockItem;
use App\Models\Unit;
use App\Services\Inventory\StockLedger;
use App\Services\Reports\AccountBalances;
use Illuminate\Http\Request;

class StockItemController extends Controller
{
    public function __construct(protected StockLedger $ledger) {}

    public function index(Request $request)
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'group' => ['nullable', 'integer'],
            'category' => ['nullable', 'integer'],
            'godown' => ['nullable', 'integer'],
            'inactive' => ['nullable', 'boolean'],
        ]);

        $items = StockItem::query()->with('unit', 'group', 'category')
            ->when($filters['search'] ?? null, fn ($q, $v) => $q->where(fn ($w) => $w->where('name', 'like', "%{$v}%")->orWhere('alias', 'like', "%{$v}%")->orWhere('code', 'like', "%{$v}%")))
            ->when($filters['group'] ?? null, fn ($q, $v) => $q->whereIn('stock_group_id', StockGroup::descendantIdsOf([(int) $v])))
            ->when($filters['category'] ?? null, fn ($q, $v) => $q->where('stock_category_id', $v))
            ->when(! ($filters['inactive'] ?? false), fn ($q) => $q->where('is_active', true))
            ->orderBy('name')
            ->paginate(50)
            ->withQueryString();

        $positions = $this->ledger->position(now()->toDateString(), $items->getCollection(), isset($filters['godown']) ? (int) $filters['godown'] : null);

        return view('inventory.items.index', [
            'items' => $items,
            'positions' => $positions,
            'filters' => $filters,
            'groups' => StockGroup::tree(),
            'categories' => StockCategory::query()->orderBy('name')->get(),
            'godowns' => Godown::query()->orderBy('name')->get(),
        ]);
    }

    public function create()
    {
        return view('inventory.items.form', $this->formData(new StockItem([
            'is_active' => true,
            'unit_id' => Unit::query()->orderBy('id')->value('id'),
            'opening_godown_id' => Godown::defaultId(),
        ])));
    }

    public function store(StockItemRequest $request)
    {
        $item = StockItem::create($request->itemData());

        return redirect()->route('stock-items.show', $item)->with('success', "Stock item {$item->name} created.");
    }

    public function show(Request $request, StockItem $stockItem)
    {
        $filters = $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from'], 'godown' => ['nullable', 'integer']]);
        [$defaultFrom, $defaultTo] = app(AccountBalances::class)->defaultPeriod();
        $from = $filters['from'] ?? $defaultFrom;
        $to = $filters['to'] ?? $defaultTo;
        $godownId = isset($filters['godown']) ? (int) $filters['godown'] : null;

        $stockItem->load('unit', 'group', 'category', 'salesAccount', 'purchaseAccount', 'openingGodown', 'bomComponents.component.unit');
        $godowns = Godown::query()->orderBy('name')->get();

        return view('inventory.items.show', [
            'item' => $stockItem,
            'position' => $this->ledger->position($to, collect([$stockItem]))->first(),
            'byGodown' => $this->ledger->godownQuantities($to, [$stockItem->id])[$stockItem->id] ?? [],
            'ledger' => $this->ledger->itemLedger($stockItem, $from, $to, $godownId),
            'godowns' => $godowns,
            'godownId' => $godownId,
            'usedIn' => StockItem::query()->whereIn('id', \App\Models\BomComponent::query()->where('component_id', $stockItem->id)->where('kind', 'component')->select('stock_item_id'))->orderBy('name')->get(),
            'comesFrom' => StockItem::query()->whereIn('id', \App\Models\BomComponent::query()->where('component_id', $stockItem->id)->where('kind', '!=', 'component')->select('stock_item_id'))->orderBy('name')->get(),
            'from' => $from,
            'to' => $to,
        ]);
    }

    public function edit(StockItem $stockItem)
    {
        return view('inventory.items.form', $this->formData($stockItem));
    }

    public function update(StockItemRequest $request, StockItem $stockItem)
    {
        $data = $request->itemData();
        if ($stockItem->unit_id !== (int) $data['unit_id'] && $stockItem->hasMovements()) {
            return back()->withInput()->withErrors(['unit_id' => 'The unit cannot be changed after the item has been used in documents.']);
        }

        $stockItem->update($data);

        return redirect()->route('stock-items.show', $stockItem)->with('success', "Stock item {$stockItem->name} updated.");
    }

    public function destroy(StockItem $stockItem)
    {
        if ($stockItem->hasMovements()) {
            return back()->with('error', 'This item appears in invoices or stock journals and cannot be deleted. Mark it inactive instead.');
        }
        if (\App\Models\OrderItem::query()->where('stock_item_id', $stockItem->id)->exists()) {
            return back()->with('error', 'This item appears in sales or purchase orders and cannot be deleted. Mark it inactive instead.');
        }
        if ($parent = \App\Models\BomComponent::query()->where('component_id', $stockItem->id)->first()) {
            return back()->with('error', 'This item is a component in the bill of materials of '.StockItem::query()->find($parent->stock_item_id)?->name.'. Remove it there first.');
        }

        $stockItem->delete();

        return redirect()->route('stock-items.index')->with('success', "Stock item {$stockItem->name} deleted.");
    }

    protected function formData(StockItem $item): array
    {
        return [
            'item' => $item,
            'groups' => StockGroup::tree(),
            'categories' => StockCategory::query()->orderBy('name')->get(),
            'units' => Unit::query()->orderBy('symbol')->get(),
            'godowns' => Godown::query()->usable()->orWhere('id', $item->opening_godown_id)->orderBy('name')->get(),
            'salesLedgers' => Account::query()->where('account_type', 'sales')->orderBy('name')->get(),
            'purchaseLedgers' => Account::query()->where('account_type', 'purchase')->orderBy('name')->get(),
        ];
    }
}
