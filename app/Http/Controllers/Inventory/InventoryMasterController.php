<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Models\Godown;
use App\Models\InvoiceItem;
use App\Models\StockCategory;
use App\Models\StockGroup;
use App\Models\StockItem;
use App\Models\StockJournalLine;
use App\Models\Unit;
use App\Support\CurrentCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Units, stock groups, stock categories and godowns: small masters that share
 * one list-and-form screen. The {master} route parameter picks which one.
 */
class InventoryMasterController extends Controller
{
    public const MASTERS = [
        'units' => ['model' => Unit::class, 'label' => 'Unit', 'plural' => 'Units of measure', 'order' => 'symbol'],
        'stock-groups' => ['model' => StockGroup::class, 'label' => 'Stock group', 'plural' => 'Stock groups', 'order' => 'name'],
        'stock-categories' => ['model' => StockCategory::class, 'label' => 'Stock category', 'plural' => 'Stock categories', 'order' => 'name'],
        'godowns' => ['model' => Godown::class, 'label' => 'Godown', 'plural' => 'Godowns / locations', 'order' => 'name'],
    ];

    public function index(Request $request, string $master)
    {
        $config = self::MASTERS[$master];
        $model = $config['model'];

        $records = $master === 'stock-groups'
            ? StockGroup::tree()
            : $model::query()->orderBy($config['order'])->get();

        $counts = $this->itemCounts($master);
        $editing = $request->filled('edit') ? $model::query()->find($request->integer('edit')) : null;

        return view('inventory.masters', [
            'master' => $master,
            'config' => $config,
            'records' => $records,
            'counts' => $counts,
            'editing' => $editing,
            'groups' => $master === 'stock-groups' ? StockGroup::tree() : collect(),
        ]);
    }

    public function store(Request $request, CurrentCompany $current, string $master)
    {
        $config = self::MASTERS[$master];
        $data = $this->validated($request, $current, $master);

        DB::transaction(function () use ($config, $data, $master) {
            $record = $config['model']::create($data);
            $this->afterSave($master, $record);
        });

        return redirect()->route('inventory-masters.index', $master)->with('success', $config['label'].' created.');
    }

    public function update(Request $request, CurrentCompany $current, string $master, int $id)
    {
        $config = self::MASTERS[$master];
        $record = $config['model']::query()->findOrFail($id);
        $data = $this->validated($request, $current, $master, $record);

        if ($master === 'godowns' && $record->is_default && ! $data['is_default']) {
            return back()->withInput()->with('error', 'Choose another godown as the default instead of switching this one off.');
        }

        DB::transaction(function () use ($record, $data, $master) {
            $record->update($data);
            $this->afterSave($master, $record);
        });

        return redirect()->route('inventory-masters.index', $master)->with('success', $config['label'].' updated.');
    }

    public function destroy(string $master, int $id)
    {
        $config = self::MASTERS[$master];
        $record = $config['model']::query()->findOrFail($id);

        if ($reason = $this->inUse($master, $record)) {
            return back()->with('error', $reason);
        }

        $record->delete();

        return redirect()->route('inventory-masters.index', $master)->with('success', $config['label'].' deleted.');
    }

    protected function validated(Request $request, CurrentCompany $current, string $master, ?Model $record = null): array
    {
        $companyId = $current->id();
        $unique = fn (string $table, string $column) => Rule::unique($table, $column)->where('company_id', $companyId)->ignore($record?->id);

        if ($master === 'units' && $request->filled('uqc')) {
            $request->merge(['uqc' => strtoupper(trim((string) $request->input('uqc')))]);
        }

        return match ($master) {
            'units' => $request->validate([
                'symbol' => ['required', 'string', 'max:20', $unique('units', 'symbol')],
                'name' => ['required', 'string', 'max:100'],
                'decimal_places' => ['required', 'integer', 'between:0,3'],
                'uqc' => ['nullable', 'string', 'max:10', 'regex:/^[A-Z]{3}$/'],
            ], ['uqc.regex' => 'The UQC is a three-letter GST code such as NOS, KGS or LTR.']),
            'stock-groups' => $request->validate([
                'name' => ['required', 'string', 'max:150', $unique('stock_groups', 'name')],
                'parent_id' => ['nullable', 'integer', Rule::exists('stock_groups', 'id')->where('company_id', $companyId),
                    Rule::notIn($record ? StockGroup::descendantIdsOf([$record->id]) : [])],
            ], ['parent_id.not_in' => 'A group cannot be placed under itself or one of its sub-groups.']),
            'stock-categories' => $request->validate([
                'name' => ['required', 'string', 'max:150', $unique('stock_categories', 'name')],
            ]),
            'godowns' => $request->validate([
                'name' => ['required', 'string', 'max:150', $unique('godowns', 'name')],
                'address' => ['nullable', 'string', 'max:500'],
            ]) + ['is_default' => $request->boolean('is_default'), 'is_active' => $request->boolean('is_default') || $request->boolean('is_active')],
        };
    }

    /** Keep exactly one default godown. */
    protected function afterSave(string $master, Model $record): void
    {
        if ($master === 'godowns' && $record->is_default) {
            Godown::query()->whereKeyNot($record->id)->where('is_default', true)->get()->each->update(['is_default' => false]);
        }
    }

    protected function inUse(string $master, Model $record): ?string
    {
        return match ($master) {
            'units' => StockItem::where('unit_id', $record->id)->exists() ? 'Stock items use this unit, so it cannot be deleted.' : null,
            'stock-groups' => StockItem::where('stock_group_id', $record->id)->exists() || StockGroup::where('parent_id', $record->id)->exists()
                ? 'This group has stock items or sub-groups, so it cannot be deleted.' : null,
            'stock-categories' => StockItem::where('stock_category_id', $record->id)->exists() ? 'Stock items use this category, so it cannot be deleted.' : null,
            'godowns' => match (true) {
                $record->is_default => 'The default godown cannot be deleted.',
                StockItem::where('opening_godown_id', $record->id)->exists()
                    || InvoiceItem::where('godown_id', $record->id)->exists()
                    || StockJournalLine::where('godown_id', $record->id)->exists() => 'Stock has moved through this godown, so it cannot be deleted. Mark it inactive instead.',
                default => null,
            },
        };
    }

    /** Number of stock items using each record, keyed by id. */
    protected function itemCounts(string $master): \Illuminate\Support\Collection
    {
        $column = ['units' => 'unit_id', 'stock-groups' => 'stock_group_id', 'stock-categories' => 'stock_category_id', 'godowns' => 'opening_godown_id'][$master];

        return StockItem::query()->whereNotNull($column)->groupBy($column)->selectRaw("{$column} as id, count(*) as c")->pluck('c', 'id');
    }
}
