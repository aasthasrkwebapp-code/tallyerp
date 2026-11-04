<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\StockItem;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class StockItemController extends Controller
{
    public function index(Company $company)
    {
        $this->authorizeCompany($company);

        $items = $company->stockItems()->with(['unit', 'group'])->orderBy('name')->get();

        return view('stock-items.index', compact('company', 'items'));
    }

    public function create(Company $company)
    {
        $this->authorizeCompany($company);

        return $this->form($company, new StockItem);
    }

    public function store(Request $request, Company $company)
    {
        $this->authorizeCompany($company);

        $company->stockItems()->create($this->validated($request, $company));

        return redirect()
            ->route('companies.stock-items.index', $company)
            ->with('success', 'Stock item created successfully.');
    }

    public function edit(Company $company, StockItem $stockItem)
    {
        $this->authorizeCompany($company);

        return $this->form($company, $stockItem);
    }

    public function update(Request $request, Company $company, StockItem $stockItem)
    {
        $this->authorizeCompany($company);

        $stockItem->update($this->validated($request, $company, $stockItem));

        return redirect()
            ->route('companies.stock-items.index', $company)
            ->with('success', 'Stock item updated successfully.');
    }

    public function destroy(Company $company, StockItem $stockItem)
    {
        $this->authorizeCompany($company);

        if ($stockItem->movements()->exists()) {
            return back()->with('error', 'This item has been used on invoices and cannot be deleted.');
        }

        $stockItem->delete();

        return redirect()
            ->route('companies.stock-items.index', $company)
            ->with('success', 'Stock item deleted successfully.');
    }

    /**
     * Printable barcode labels: ?items[id]=copies
     */
    public function labels(Request $request, Company $company)
    {
        $this->authorizeCompany($company);

        $request->validate(['items' => ['nullable', 'array'], 'items.*' => ['nullable', 'integer', 'min:0', 'max:500']]);
        $copies = collect($request->input('items', []))->map(fn ($n) => (int) $n)->filter();

        $items = $company->stockItems()->whereNotNull('barcode')->orderBy('name')->get();
        $labels = $items->filter(fn ($i) => $copies->has($i->id))
            ->flatMap(fn ($i) => array_fill(0, $copies[$i->id], $i));

        return view('stock-items.labels', compact('company', 'items', 'labels', 'copies'));
    }

    /**
     * Suggest a free 13-digit barcode for an item without one.
     */
    public static function suggestBarcode(Company $company): string
    {
        do {
            $code = '2'.str_pad((string) random_int(0, 999999999999), 12, '0', STR_PAD_LEFT);
        } while ($company->stockItems()->where('barcode', $code)->exists());

        return $code;
    }

    protected function form(Company $company, StockItem $item)
    {
        return view('stock-items.form', [
            'company' => $company,
            'item' => $item,
            'units' => $company->units()->orderBy('symbol')->get(),
            'groups' => $company->stockGroups()->orderBy('name')->get(),
        ]);
    }

    protected function validated(Request $request, Company $company, ?StockItem $item = null): array
    {
        $data = $request->validate([
            'name' => [
                'required', 'string', 'max:255',
                Rule::unique('stock_items', 'name')->where('company_id', $company->id)->ignore($item?->id),
            ],
            'unit_id' => ['required', Rule::exists('units', 'id')->where('company_id', $company->id)],
            'stock_group_id' => ['nullable', Rule::exists('stock_groups', 'id')->where('company_id', $company->id)],
            'hsn_sac' => ['nullable', 'string', 'max:10', 'regex:/^[0-9]+$/'],
            'gst_rate' => ['nullable', Rule::in(GstInvoiceController::RATES)],
            'opening_quantity' => ['nullable', 'numeric', 'min:0', 'max:9999999999.999'],
            'opening_rate' => ['nullable', 'numeric', 'min:0', 'max:9999999999999.99'],
            'selling_price' => ['nullable', 'numeric', 'min:0', 'max:9999999999999.99'],
            'barcode' => ['nullable', 'string', 'max:48', 'regex:/^[\x20-\x7E]+$/', Rule::unique('stock_items', 'barcode')->where('company_id', $company->id)->ignore($item?->id)],
        ]);

        $data['opening_quantity'] = $data['opening_quantity'] ?? 0;
        $data['track_batches'] = $request->boolean('track_batches');
        $data['track_serials'] = $request->boolean('track_serials');
        $data['opening_rate'] = $data['opening_rate'] ?? 0;

        return $data;
    }
}
