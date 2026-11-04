<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\PriceList;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Price lists (wholesale, retail, dealer…): a price per item, used on bills
 * for the parties the list is assigned to.
 */
class PriceListController extends Controller
{
    public function index(Company $company)
    {
        $this->authorizeCompany($company);

        $lists = $company->priceLists()->withCount(['items', 'parties'])->orderBy('name')->get();

        return view('price-lists.index', compact('company', 'lists'));
    }

    public function store(Request $request, Company $company)
    {
        $this->authorizeCompany($company);

        $list = $company->priceLists()->create($request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('price_lists')->where('company_id', $company->id)],
        ]));

        return redirect()->route('companies.price-lists.edit', [$company, $list])->with('success', 'Price list created. Enter its prices.');
    }

    public function edit(Company $company, PriceList $priceList)
    {
        $this->authorizeCompany($company);
        abort_unless((int) $priceList->company_id === (int) $company->id, 404);

        return view('price-lists.edit', [
            'company' => $company,
            'list' => $priceList,
            'items' => $company->stockItems()->with('unit')->orderBy('name')->get(),
            'prices' => $priceList->items()->pluck('price', 'stock_item_id'),
        ]);
    }

    public function update(Request $request, Company $company, PriceList $priceList)
    {
        $this->authorizeCompany($company);
        abort_unless((int) $priceList->company_id === (int) $company->id, 404);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('price_lists')->where('company_id', $company->id)->ignore($priceList->id)],
            'prices' => ['array'],
            'prices.*' => ['nullable', 'numeric', 'min:0', 'max:9999999999999.99'],
        ]);
        $itemIds = $company->stockItems()->pluck('id')->all();

        DB::transaction(function () use ($priceList, $data, $itemIds) {
            $priceList->update(['name' => $data['name']]);

            foreach ($data['prices'] ?? [] as $itemId => $price) {
                if (! in_array((int) $itemId, $itemIds)) {
                    continue;
                }
                // Blank means "use the item's normal selling price".
                $price === null || $price === ''
                    ? $priceList->items()->where('stock_item_id', $itemId)->delete()
                    : $priceList->items()->updateOrCreate(['stock_item_id' => (int) $itemId], ['price' => round((float) $price, 2)]);
            }
        });

        return redirect()->route('companies.price-lists.index', $company)->with('success', $data['name'].' saved.');
    }

    public function destroy(Company $company, PriceList $priceList)
    {
        $this->authorizeCompany($company);
        abort_unless((int) $priceList->company_id === (int) $company->id, 404);

        $priceList->delete(); // parties fall back to normal prices

        return back()->with('success', $priceList->name.' deleted.');
    }

    /**
     * For bill forms: party id → list id, and list id → item id → price.
     *
     * @return array{parties: array<int, int>, prices: array<int, array<int, float>>}
     */
    public static function forForm(Company $company): array
    {
        return [
            'parties' => $company->accounts()->whereNotNull('price_list_id')->pluck('price_list_id', 'id')->all(),
            'prices' => DB::table('price_list_items')
                ->join('price_lists', 'price_lists.id', '=', 'price_list_items.price_list_id')
                ->where('price_lists.company_id', $company->id)
                ->get(['price_list_items.price_list_id', 'price_list_items.stock_item_id', 'price_list_items.price'])
                ->groupBy('price_list_id')
                ->map(fn ($rows) => $rows->mapWithKeys(fn ($r) => [$r->stock_item_id => (float) $r->price])->all())
                ->all(),
        ];
    }
}
