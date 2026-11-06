<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\StockGroup;
use App\Models\Unit;
use App\Support\Books;
use App\Support\Stock;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Inventory setup (units and stock groups) and the Stock Summary.
 */
class InventoryController extends Controller
{
    public function setup(Company $company)
    {
        $this->authorizeCompany($company);

        return view('inventory.setup', [
            'company' => $company,
            'units' => $company->units()->withCount('stockItems')->orderBy('symbol')->get(),
            'groups' => $company->stockGroups()->with('parent')->withCount('stockItems')->orderBy('name')->get(),
        ]);
    }

    public function storeUnit(Request $request, Company $company)
    {
        $this->authorizeCompany($company);

        $company->units()->create($request->validate([
            'symbol' => ['required', 'string', 'max:20', Rule::unique('units')->where('company_id', $company->id)],
            'name' => ['nullable', 'string', 'max:255'],
            'decimals' => ['required', 'integer', 'between:0,3'],
        ]));

        return back()->with('success', 'Unit added.');
    }

    public function destroyUnit(Company $company, Unit $unit)
    {
        $this->authorizeCompany($company);
        abort_unless((int) $unit->company_id === (int) $company->id, 404);

        if ($unit->stockItems()->exists()) {
            return back()->with('error', 'This unit is used by stock items.');
        }

        $unit->delete();

        return back()->with('success', 'Unit deleted.');
    }

    public function storeGroup(Request $request, Company $company)
    {
        $this->authorizeCompany($company);

        $company->stockGroups()->create($request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('stock_groups')->where('company_id', $company->id)],
            'parent_id' => ['nullable', Rule::exists('stock_groups', 'id')->where('company_id', $company->id)],
        ]));

        return back()->with('success', 'Stock group added.');
    }

    public function destroyGroup(Company $company, StockGroup $stockGroup)
    {
        $this->authorizeCompany($company);
        abort_unless((int) $stockGroup->company_id === (int) $company->id, 404);

        $stockGroup->delete();

        return back()->with('success', 'Stock group deleted.');
    }

    /**
     * Stock left in each batch, with expired and soon-expiring batches flagged.
     */
    public function batches(Request $request, Company $company)
    {
        $this->authorizeCompany($company);

        $request->validate(['show' => ['nullable', 'in:in_stock,expiring,all']]);
        $show = $request->input('show', 'in_stock');

        $rows = $company->stockBatches()->with('stockItem.unit')->get()
            ->map(function ($batch) {
                $in = (float) $batch->movements()->sum('quantity_in');
                $out = (float) $batch->movements()->sum('quantity_out');

                return (object) ['batch' => $batch, 'in' => round($in, 3), 'out' => round($out, 3), 'left' => round($in - $out, 3), 'status' => $batch->expiryStatus()];
            })
            ->filter(fn ($r) => match ($show) {
                'all' => true,
                'expiring' => $r->left > 0 && in_array($r->status, ['expired', 'expiring'], true),
                default => $r->left > 0,
            })
            // Soonest expiry first; batches without expiry last.
            ->sortBy(fn ($r) => [$r->batch->expiry_date?->toDateString() ?? '9999-12-31', $r->batch->stockItem->name])
            ->values();

        return view('inventory.batches', compact('company', 'rows', 'show'));
    }

    /**
     * Find a serial number: when it came in, from whom, and who it went to.
     */
    public function serials(Request $request, Company $company)
    {
        $this->authorizeCompany($company);

        $request->validate(['q' => ['nullable', 'string', 'max:60'], 'item' => ['nullable', 'integer']]);
        $search = strtoupper(trim((string) $request->input('q')));

        $units = $company->serialNumbers()
            ->with(['stockItem', 'inVoucher.party', 'outVoucher.party'])
            ->when($search !== '', fn ($q) => $q->where('serial', 'like', '%'.$search.'%'))
            ->when($request->filled('item'), fn ($q) => $q->where('stock_item_id', $request->integer('item')))
            ->orderBy('serial')
            ->limit(500)
            ->get();

        $items = $company->stockItems()->where('track_serials', true)->orderBy('name')->get();

        return view('inventory.serials', compact('company', 'units', 'items', 'search'));
    }

    public function summary(Request $request, Company $company)
    {
        $this->authorizeCompany($company);

        $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date']]);
        [$fyStart, $fyEnd] = (new Books($company))->financialYear();
        $from = $request->filled('from') ? CarbonImmutable::parse($request->input('from')) : $fyStart;
        $to = $request->filled('to') ? CarbonImmutable::parse($request->input('to')) : $fyEnd;

        $rows = (new Stock($company))->summary($from, $to);

        return view('inventory.summary', compact('company', 'from', 'to', 'rows'));
    }

    public function lowStock(Company $company)
    {
        $this->authorizeCompany($company);

        $on = self::stockDate($company);
        $rows = self::lowStockRows($company, $on);

        return view('inventory.low-stock', compact('company', 'on', 'rows'));
    }

    /**
     * Today, kept inside the financial year.
     */
    public static function stockDate(Company $company): CarbonImmutable
    {
        [$fyStart, $fyEnd] = (new Books($company))->financialYear();

        return CarbonImmutable::today()->max($fyStart)->min($fyEnd);
    }

    /**
     * Items with a reorder level whose closing stock is at or below it.
     */
    public static function lowStockRows(Company $company, CarbonImmutable $on)
    {
        if (! $company->stockItems()->whereNotNull('reorder_level')->exists()) {
            return collect();
        }
        [$fyStart] = (new Books($company))->financialYear();

        return (new Stock($company))->summary($fyStart, $on)
            ->filter(fn ($r) => $r->item->reorder_level !== null && $r->closing_qty <= (float) $r->item->reorder_level)
            ->map(function ($r) {
                $r->short = round((float) $r->item->reorder_level - $r->closing_qty, 3);
                $r->rate = $r->closing_qty > 0 ? $r->closing_value / $r->closing_qty : (float) $r->item->opening_rate;

                return $r;
            })
            ->values();
    }
}
