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
}
