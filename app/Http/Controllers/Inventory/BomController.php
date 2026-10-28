<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Models\Godown;
use App\Models\StockItem;
use App\Services\Inventory\BillOfMaterials;
use App\Services\Inventory\StockJournalService;
use App\Support\Decimal;
use Illuminate\Http\Request;

/** A stock item's bill of materials, and manufacturing from it. */
class BomController extends Controller
{
    public function __construct(protected BillOfMaterials $boms) {}

    public function edit(StockItem $stockItem)
    {
        $stockItem->load('unit', 'bomComponents.component.unit');

        return view('inventory.items.bom', [
            'item' => $stockItem,
            'items' => StockItem::query()->with('unit')->where('is_active', true)->whereKeyNot($stockItem->id)->orderBy('name')->get(),
        ]);
    }

    public function update(Request $request, StockItem $stockItem)
    {
        $data = $request->validate([
            'bom_quantity' => ['required', 'string', 'max:20'],
            'components' => ['nullable', 'array', 'max:100'],
            'components.*' => ['array'],
        ]);
        $this->boms->save($stockItem->load('unit'), $data);

        return redirect()->route('stock-items.show', $stockItem)->with('success', "Bill of materials of {$stockItem->name} saved.");
    }

    public function destroy(StockItem $stockItem)
    {
        $this->boms->remove($stockItem);

        return redirect()->route('stock-items.show', $stockItem)->with('success', "Bill of materials of {$stockItem->name} removed.");
    }

    /** The manufacture form, with what the quantity needs and what is in stock. */
    public function create(Request $request, StockItem $stockItem)
    {
        $stockItem->load('unit', 'bomComponents.component.unit');
        if ($stockItem->bomComponents->isEmpty()) {
            return redirect()->route('stock-items.bom.edit', $stockItem)->with('error', 'Set up the bill of materials first.');
        }
        $qty = str_replace(',', '', (string) $request->input('quantity', Decimal::trim((string) $stockItem->bom_quantity)));
        $date = $request->input('date', now()->toDateString());
        $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $date) ? $date : now()->toDateString();
        $qtyMilli = preg_match('/^\d{1,7}(\.\d{1,3})?$/', $qty) ? Decimal::toScaled($qty, 3) : 0;

        return view('inventory.items.manufacture', [
            'item' => $stockItem,
            'quantity' => $qty,
            'date' => $date,
            'needs' => $qtyMilli > 0 ? $this->boms->requirements($stockItem, $qtyMilli, $date) : [],
            'godowns' => Godown::query()->where('is_active', true)->orderBy('name')->get(),
            'defaultGodown' => Godown::defaultId(),
        ]);
    }

    public function store(Request $request, StockItem $stockItem, StockJournalService $journals)
    {
        $data = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'quantity' => ['required', 'string', 'max:20'],
            'godown_id' => ['required', 'integer'],
            'component_godown_id' => ['nullable', 'integer'],
            'other_cost' => ['nullable', 'string', 'max:20'],
            'narration' => ['nullable', 'string', 'max:1000'],
        ]);
        $journal = $this->boms->manufacture($stockItem, $data, $request->user());
        $negative = $journals->negativeItems($journal->lines->pluck('stock_item_id')->unique()->all(), $journal->date->toDateString());

        return redirect()->route('stock-journals.show', $journal)
            ->with('success', "Stock journal {$journal->number} saved: {$stockItem->name} manufactured.")
            ->with('warning', $negative ? 'Stock is now negative for '.implode('; ', $negative).'.' : null);
    }
}
