<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\Godown;
use App\Models\StockTransfer;
use App\Support\Books;
use App\Support\Stock;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Godowns (storage locations), stock by location, and the stock journal
 * that moves stock between them.
 */
class GodownController extends Controller
{
    public function index(Company $company)
    {
        $this->authorizeCompany($company);

        $godowns = $company->godowns()->orderBy('name')->get();
        [, $fyEnd] = (new Books($company))->financialYear();
        $on = CarbonImmutable::today()->min($fyEnd);

        return view('inventory.godowns', [
            'company' => $company,
            'godowns' => $godowns,
            'on' => $on,
            'stock' => self::stockByGodown($company, $on),
            'items' => $company->stockItems()->with('unit')->orderBy('name')->get(),
            'transfers' => $company->stockTransfers()->with('movements.stockItem.unit', 'movements.godown')->latest('date')->latest('id')->limit(20)->get(),
        ]);
    }

    public function store(Request $request, Company $company)
    {
        $this->authorizeCompany($company);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:100', 'not_in:'.Godown::MAIN, Rule::unique('godowns', 'name')->where('company_id', $company->id)],
        ]);
        $company->godowns()->create($data);

        return back()->with('success', 'Godown "'.$data['name'].'" added.');
    }

    public function destroy(Company $company, Godown $godown)
    {
        $this->authorizeCompany($company);
        abort_unless((int) $godown->company_id === (int) $company->id, 404);

        if ($godown->movements()->exists()) {
            return back()->with('error', 'Stock has moved through '.$godown->name.'; it can\'t be deleted.');
        }
        $godown->delete();

        return back()->with('success', 'Godown deleted.');
    }

    /**
     * Stock journal: move a quantity of an item from one godown to another at average cost.
     */
    public function transfer(Request $request, Company $company)
    {
        $this->authorizeCompany($company);

        [$fyStart, $fyEnd] = (new Books($company))->financialYear();
        $godownIds = $company->godowns()->pluck('id')->all();
        $location = ['nullable', Rule::in($godownIds)];

        $data = $request->validate([
            'date' => ['required', 'date', 'after_or_equal:'.$fyStart->toDateString(), 'before_or_equal:'.$fyEnd->toDateString()],
            'stock_item_id' => ['required', Rule::exists('stock_items', 'id')->where('company_id', $company->id)],
            'from_godown_id' => $location,
            'to_godown_id' => $location,
            'quantity' => ['required', 'numeric', 'gt:0', 'max:9999999999.999'],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);
        $from = $data['from_godown_id'] ?? null;
        $to = $data['to_godown_id'] ?? null;
        $qty = round((float) $data['quantity'], 3);

        if ((string) $from === (string) $to) {
            throw ValidationException::withMessages(['to_godown_id' => 'Choose a different godown to move the stock to.']);
        }

        $date = CarbonImmutable::parse($data['date']);
        $available = (float) (self::stockByGodown($company, $date)[$data['stock_item_id']][(int) $from] ?? 0);
        if ($qty > $available + 0.0005) {
            throw ValidationException::withMessages(['quantity' => 'Only '.rtrim(rtrim(number_format($available, 3), '0'), '.').' in '.self::name($company, $from).' on that date.']);
        }

        $rate = (float) ((new Stock($company))->summary($date, $date)->firstWhere('item.id', (int) $data['stock_item_id'])?->rate ?? 0);

        $transfer = DB::transaction(function () use ($company, $data, $from, $to, $qty, $rate) {
            $transfer = $company->stockTransfers()->create([
                'number' => (int) $company->stockTransfers()->lockForUpdate()->max('number') + 1,
                'date' => $data['date'],
                'notes' => $data['notes'] ?? null,
            ]);
            $move = fn (?int $godown, float $in, float $out) => $company->stockMovements()->create([
                'stock_transfer_id' => $transfer->id, 'stock_item_id' => $data['stock_item_id'], 'godown_id' => $godown,
                'date' => $data['date'], 'quantity_in' => $in, 'quantity_out' => $out, 'rate' => $rate, 'amount' => round($qty * $rate, 2),
            ]);
            $move($from ? (int) $from : null, 0, $qty);
            $move($to ? (int) $to : null, $qty, 0);

            return $transfer;
        });

        return back()->with('success', 'Stock journal #'.$transfer->number.': moved to '.self::name($company, $to).'.');
    }

    public function destroyTransfer(Company $company, StockTransfer $transfer)
    {
        $this->authorizeCompany($company);
        abort_unless((int) $transfer->company_id === (int) $company->id, 404);

        $transfer->delete(); // its movements go with it

        return back()->with('success', 'Stock journal #'.$transfer->number.' deleted.');
    }

    /**
     * Quantity of every item in every location on a date: [item id][godown id, 0 = main] => qty.
     *
     * @return array<int, array<int, float>>
     */
    public static function stockByGodown(Company $company, CarbonImmutable $on): array
    {
        $stock = [];
        foreach ($company->stockItems()->get(['id', 'opening_quantity']) as $item) {
            $stock[$item->id][0] = (float) $item->opening_quantity;
        }

        $company->stockMovements()
            ->whereDate('date', '<=', $on->toDateString())
            ->groupBy('stock_item_id', 'godown_id')
            ->selectRaw('stock_item_id, godown_id, SUM(quantity_in) - SUM(quantity_out) AS net')
            ->get()
            ->each(function ($m) use (&$stock) {
                $key = (int) $m->godown_id;
                $stock[$m->stock_item_id][$key] = round(($stock[$m->stock_item_id][$key] ?? 0) + (float) $m->net, 3);
            });

        return $stock;
    }

    public static function name(Company $company, $godownId): string
    {
        return $godownId ? $company->godowns()->whereKey($godownId)->value('name') : Godown::MAIN;
    }
}
