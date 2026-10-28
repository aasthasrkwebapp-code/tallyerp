<?php

namespace App\Services\Inventory;

use App\Models\AuditLog;
use App\Models\BomComponent;
use App\Models\Godown;
use App\Models\StockItem;
use App\Models\StockJournal;
use App\Models\User;
use App\Support\Decimal;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Bills of materials and manufacturing from them.
 *
 * A bill of materials lists the components that make bom_quantity units of an
 * item. Manufacturing posts a "Manufacture / consumption" stock journal: each
 * component goes out of its godown, and the finished item comes in valued at
 * the cost of the components used (at their costing method) plus any other
 * cost entered, such as labour.
 */
class BillOfMaterials
{
    public function __construct(protected StockJournalService $journals, protected StockLedger $ledger) {}

    /**
     * @param  array{bom_quantity: string, components: array<int, array{component_id?: ?int, quantity?: ?string}>}  $data
     */
    public function save(StockItem $item, array $data): StockItem
    {
        $errors = [];
        $output = str_replace(',', '', trim((string) ($data['bom_quantity'] ?? '')));
        if (! $this->validQuantity($output, $item)) {
            $errors['bom_quantity'] = "Enter how many {$item->unit->symbol} the components make, with at most {$item->unit->decimal_places} decimals.";
        }

        $rows = [];
        $seen = [];
        $input = array_values($data['components'] ?? []);
        $components = StockItem::query()->with('unit')->whereIn('id', array_filter(array_map(fn ($c) => (int) ($c['component_id'] ?? 0), $input)))->get()->keyBy('id');
        foreach ($input as $i => $line) {
            $id = (int) ($line['component_id'] ?? 0);
            $qty = str_replace(',', '', trim((string) ($line['quantity'] ?? '')));
            if (! $id && $qty === '') {
                continue;
            }
            $label = 'Line '.($i + 1);
            $component = $components->get($id);
            if (! $component) {
                $errors["components.$i.component_id"] = "{$label}: choose a stock item.";

                continue;
            }
            if ($component->id === $item->id) {
                $errors["components.$i.component_id"] = "{$label}: an item cannot be a component of itself.";

                continue;
            }
            if ($this->usesItem($component, $item->id)) {
                $errors["components.$i.component_id"] = "{$label}: {$component->name} is itself made from {$item->name}.";

                continue;
            }
            if (isset($seen[$id])) {
                $errors["components.$i.component_id"] = "{$label}: {$component->name} is already listed.";

                continue;
            }
            if (! $this->validQuantity($qty, $component)) {
                $errors["components.$i.quantity"] = "{$label}: enter a quantity greater than zero in {$component->unit->symbol}, with at most {$component->unit->decimal_places} decimals.";

                continue;
            }
            $seen[$id] = true;
            $rows[] = ['component_id' => $id, 'quantity' => $qty];
        }
        if (! $rows && ! $errors) {
            $errors['components'] = 'Add at least one component.';
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        return DB::transaction(function () use ($item, $output, $rows) {
            $before = $item->bomComponents()->with('component')->get()->map(fn ($c) => $c->component->name.' '.Decimal::trim($c->quantity))->implode(', ');
            $item->update(['bom_quantity' => $output]);
            $item->bomComponents()->delete();
            foreach ($rows as $n => $row) {
                $item->bomComponents()->create($row + ['line_no' => $n + 1]);
            }
            $after = $item->bomComponents()->with('component')->get()->map(fn ($c) => $c->component->name.' '.Decimal::trim($c->quantity))->implode(', ');
            AuditLog::record('updated', $item, ['bill_of_materials' => $before ?: null], ['bill_of_materials' => $after], "Bill of materials of {$item->name} saved");

            return $item->fresh('bomComponents.component.unit');
        });
    }

    public function remove(StockItem $item): void
    {
        DB::transaction(function () use ($item) {
            $item->bomComponents()->delete();
            $item->update(['bom_quantity' => null]);
            AuditLog::record('updated', $item, null, null, "Bill of materials of {$item->name} removed");
        });
    }

    /**
     * Components needed to make $quantity (thousandths) of the item, with what is in stock
     * on the date and the cost of issuing them.
     *
     * @return array<int, object{component: StockItem, quantity: int, in_stock: int, cost: int}>
     */
    public function requirements(StockItem $item, int $quantity, string $date): array
    {
        $item->loadMissing('bomComponents.component.unit');
        $output = Decimal::toScaled((string) $item->bom_quantity, 3);
        $positions = $this->ledger->position($date, $item->bomComponents->pluck('component'));
        $out = [];
        foreach ($item->bomComponents as $line) {
            $component = $line->component;
            // Rounded up to what the component's unit can hold.
            $step = 10 ** (3 - $component->unit->decimal_places);
            $den = max($output, 1) * $step;
            $needed = intdiv(Decimal::toScaled((string) $line->quantity, 3) * $quantity + $den - 1, $den) * $step;
            $out[] = (object) [
                'component' => $component,
                'quantity' => $needed,
                'in_stock' => $positions[$component->id]->quantity ?? 0,
                'cost' => $this->ledger->issueCost($component, $needed, $date),
            ];
        }

        return $out;
    }

    /**
     * @param  array{date: string, quantity: string, godown_id: int, component_godown_id?: ?int, other_cost?: ?string, narration?: ?string}  $data
     */
    public function manufacture(StockItem $item, array $data, User $user): StockJournal
    {
        $item->loadMissing('unit', 'bomComponents.component.unit');
        if ($item->bomComponents->isEmpty()) {
            throw ValidationException::withMessages(['quantity' => 'This item has no bill of materials.']);
        }
        $date = $this->journals->validDate($data['date'] ?? null);
        $errors = [];
        $qty = str_replace(',', '', trim((string) ($data['quantity'] ?? '')));
        if (! $this->validQuantity($qty, $item)) {
            $errors['quantity'] = "Enter the quantity to make in {$item->unit->symbol}, with at most {$item->unit->decimal_places} decimals.";
        }
        $godowns = Godown::query()->where('is_active', true)->pluck('id')->all();
        $godown = (int) ($data['godown_id'] ?? 0);
        $componentGodown = (int) ($data['component_godown_id'] ?? 0) ?: $godown;
        if (! in_array($godown, $godowns, true)) {
            $errors['godown_id'] = 'Choose the godown the finished goods go into.';
        }
        if (! in_array($componentGodown, $godowns, true)) {
            $errors['component_godown_id'] = 'Choose the godown the components come from.';
        }
        $other = str_replace(',', '', trim((string) ($data['other_cost'] ?? '')));
        if ($other !== '' && ! preg_match('/^\d{1,11}(\.\d{1,2})?$/', $other)) {
            $errors['other_cost'] = 'Enter a valid amount.';
        }
        $tracked = collect([$item])->concat($item->bomComponents->pluck('component'))->filter(fn ($i) => $i->tracking !== 'none');
        if ($tracked->isNotEmpty()) {
            $errors['quantity'] = $tracked->pluck('name')->implode(', ').' '.($tracked->count() === 1 ? 'is' : 'are').' tracked by batch or serial number. Use a stock journal to enter them.';
        }
        if ($inactive = $item->bomComponents->pluck('component')->reject->is_active->pluck('name')->implode(', ')) {
            $errors['quantity'] = "Inactive components: {$inactive}.";
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        $qtyMilli = Decimal::toScaled($qty, 3);
        $needs = $this->requirements($item, $qtyMilli, $date);
        $cost = array_sum(array_column($needs, 'cost')) + ($other !== '' ? Money::toPaise($other) : 0);
        $rate = Decimal::mulDiv($cost, 1000, $qtyMilli);

        $lines = array_map(fn ($n) => [
            'stock_item_id' => $n->component->id, 'godown_id' => $componentGodown, 'direction' => 'out',
            'quantity' => Decimal::fromScaled($n->quantity, 3), 'rate' => '',
        ], $needs);
        $lines[] = ['stock_item_id' => $item->id, 'godown_id' => $godown, 'direction' => 'in', 'quantity' => $qty, 'rate' => Money::fromPaise($rate)];

        $narration = trim((string) ($data['narration'] ?? ''));
        $narration = 'Manufactured '.StockLedger::qty($qtyMilli, $item->unit).' '.$item->name.' from its bill of materials'
            .($other !== '' && (float) $other > 0 ? ' (other costs '.Money::format($other).')' : '').($narration !== '' ? '. '.$narration : '');

        return $this->journals->create(['date' => $date, 'purpose' => 'production', 'narration' => $narration, 'lines' => $lines], $user);
    }

    protected function validQuantity(string $qty, StockItem $item): bool
    {
        if (! preg_match('/^\d{1,7}(\.\d{1,3})?$/', $qty) || (float) $qty <= 0) {
            return false;
        }

        return ! str_contains($qty, '.') || strlen(rtrim(explode('.', $qty)[1], '0')) <= $item->unit->decimal_places;
    }

    /** True when $component is made (at any depth) from the item with id $itemId. */
    protected function usesItem(StockItem $component, int $itemId, int $depth = 0): bool
    {
        if ($depth > 20) {
            return true;
        }
        foreach (BomComponent::query()->where('stock_item_id', $component->id)->pluck('component_id') as $childId) {
            if ($childId === $itemId || $this->usesItem(StockItem::query()->findOrFail($childId), $itemId, $depth + 1)) {
                return true;
            }
        }

        return false;
    }
}
