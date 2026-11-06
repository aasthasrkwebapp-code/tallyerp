<?php

namespace App\Http\Controllers;

use App\Models\Bom;
use App\Models\Company;
use App\Models\Production;
use App\Support\Stock;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Bills of materials and production runs (raw materials → finished goods).
 * Stock only: no ledger entries, like Tally's manufacturing journal.
 */
class ManufacturingController extends Controller
{
    public function index(Company $company)
    {
        $this->authorizeCompany($company);

        return view('manufacturing.index', [
            'company' => $company,
            'boms' => $company->boms()->with(['stockItem.unit', 'components.stockItem.unit'])->orderBy('name')->get(),
            'productions' => $company->productions()->with('bom.stockItem.unit')->orderByDesc('date')->orderByDesc('id')->limit(50)->get(),
        ]);
    }

    public function createBom(Company $company)
    {
        $this->authorizeCompany($company);

        return view('manufacturing.bom', ['company' => $company, 'bom' => new Bom(['output_quantity' => 1]), 'items' => $this->items($company)]);
    }

    public function storeBom(Request $request, Company $company)
    {
        $this->authorizeCompany($company);

        [$data, $components] = $this->validatedBom($request, $company);
        DB::transaction(function () use ($company, $data, $components) {
            $company->boms()->create($data)->components()->createMany($components);
        });

        return redirect()->route('companies.manufacturing.index', $company)->with('success', 'Bill of materials saved.');
    }

    public function editBom(Company $company, Bom $bom)
    {
        $this->authorizeCompany($company);
        $this->owns($company, $bom);

        $bom->load('components');

        return view('manufacturing.bom', ['company' => $company, 'bom' => $bom, 'items' => $this->items($company)]);
    }

    public function updateBom(Request $request, Company $company, Bom $bom)
    {
        $this->authorizeCompany($company);
        $this->owns($company, $bom);

        [$data, $components] = $this->validatedBom($request, $company, $bom);
        DB::transaction(function () use ($bom, $data, $components) {
            $bom->update($data);
            $bom->components()->delete();
            $bom->components()->createMany($components);
        });

        return redirect()->route('companies.manufacturing.index', $company)->with('success', 'Bill of materials updated.');
    }

    public function destroyBom(Company $company, Bom $bom)
    {
        $this->authorizeCompany($company);
        $this->owns($company, $bom);

        if ($bom->productions()->exists()) {
            return back()->with('error', 'This BOM has production entries. Delete those first.');
        }
        $bom->delete();

        return back()->with('success', 'Bill of materials deleted.');
    }

    public function createProduction(Request $request, Company $company)
    {
        $this->authorizeCompany($company);

        $boms = $company->boms()->with(['stockItem.unit', 'components.stockItem.unit'])->orderBy('name')->get();
        if ($boms->isEmpty()) {
            return redirect()->route('companies.manufacturing.bom.create', $company)->with('error', 'Create a bill of materials first.');
        }

        return view('manufacturing.production', ['company' => $company, 'boms' => $boms, 'selected' => $request->integer('bom') ?: $boms->first()->id]);
    }

    public function storeProduction(Request $request, Company $company)
    {
        $this->authorizeCompany($company);

        $data = $request->validate([
            'bom_id' => ['required', Rule::exists('boms', 'id')->where('company_id', $company->id)],
            'date' => ['required', 'date'],
            'quantity' => ['required', 'numeric', 'gt:0', 'max:9999999999'],
            'other_cost' => ['nullable', 'numeric', 'min:0', 'max:9999999999999.99'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $bom = $company->boms()->with('components.stockItem.unit', 'stockItem')->findOrFail($data['bom_id']);
        $date = CarbonImmutable::parse($data['date']);
        $scale = (float) $data['quantity'] / (float) $bom->output_quantity;

        // Components needed, at their average cost on the production date.
        $stock = (new Stock($company))->summary($date, $date)->keyBy(fn ($row) => $row->item->id);
        $consumed = [];
        $materialCost = 0.0;
        foreach ($bom->components as $component) {
            $needed = round((float) $component->quantity * $scale, 3);
            $row = $stock->get($component->stock_item_id);
            $available = $row ? $row->closing_qty : 0.0;

            if ($needed > $available + 0.0005) {
                throw ValidationException::withMessages([
                    'quantity' => 'Not enough '.$component->stockItem->name.': need '.self::qty($needed).' '.$component->stockItem->unit->symbol
                        .', have '.self::qty($available).'.',
                ]);
            }

            $rate = $row ? (float) $row->rate : 0.0;
            $amount = round($needed * $rate, 2);
            $consumed[] = ['item' => $component->stock_item_id, 'qty' => $needed, 'rate' => $rate, 'amount' => $amount];
            $materialCost += $amount;
        }

        $production = DB::transaction(function () use ($company, $bom, $data, $consumed, $materialCost) {
            $production = $company->productions()->create([
                'bom_id' => $bom->id,
                'number' => (int) $company->productions()->lockForUpdate()->max('number') + 1,
                'date' => $data['date'],
                'quantity' => $data['quantity'],
                'material_cost' => round($materialCost, 2),
                'other_cost' => $data['other_cost'] ?? 0,
                'notes' => $data['notes'] ?? null,
            ]);

            foreach ($consumed as $c) {
                $company->stockMovements()->create([
                    'production_id' => $production->id, 'stock_item_id' => $c['item'], 'date' => $data['date'],
                    'quantity_in' => 0, 'quantity_out' => $c['qty'], 'rate' => $c['rate'], 'amount' => $c['amount'],
                ]);
            }

            // Finished goods come in at the cost of what went into them.
            $total = $production->totalCost();
            $company->stockMovements()->create([
                'production_id' => $production->id, 'stock_item_id' => $bom->stock_item_id, 'date' => $data['date'],
                'quantity_in' => $data['quantity'], 'quantity_out' => 0,
                'rate' => round($total / (float) $data['quantity'], 2), 'amount' => $total,
            ]);

            return $production;
        });

        return redirect()->route('companies.manufacturing.index', $company)
            ->with('success', 'Production #'.$production->number.' saved: '.self::qty((float) $data['quantity']).' '.$bom->stockItem->name.' at ₹ '.inr($production->totalCost()).'.');
    }

    public function destroyProduction(Company $company, Production $production)
    {
        $this->authorizeCompany($company);
        $this->owns($company, $production);

        $production->delete(); // its stock movements go with it

        return back()->with('success', 'Production #'.$production->number.' deleted.');
    }

    protected function items(Company $company)
    {
        return $company->stockItems()->with('unit')->orderBy('name')->get();
    }

    /**
     * @return array{0: array, 1: array<int, array>}
     */
    protected function validatedBom(Request $request, Company $company, ?Bom $bom = null): array
    {
        $inCompany = fn () => Rule::exists('stock_items', 'id')->where('company_id', $company->id);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('boms')->where('company_id', $company->id)->ignore($bom?->id)],
            'stock_item_id' => ['required', $inCompany()],
            'output_quantity' => ['required', 'numeric', 'gt:0', 'max:9999999999'],
            'components' => ['required', 'array', 'min:1'],
            'components.*.stock_item_id' => ['required', $inCompany(), 'different:stock_item_id', 'distinct'],
            'components.*.quantity' => ['required', 'numeric', 'gt:0', 'max:9999999999'],
        ], [
            'components.*.stock_item_id.different' => 'An item can\'t be a component of itself.',
            'components.*.stock_item_id.distinct' => 'Each component should appear once.',
        ]);

        $components = array_map(fn ($c) => ['stock_item_id' => (int) $c['stock_item_id'], 'quantity' => round((float) $c['quantity'], 3)], array_values($data['components']));
        unset($data['components']);

        return [$data, $components];
    }

    protected function owns(Company $company, Bom|Production $model): void
    {
        abort_unless((int) $model->company_id === (int) $company->id, 404);
    }

    public static function qty(float $value): string
    {
        return rtrim(rtrim(number_format($value, 3), '0'), '.');
    }
}
