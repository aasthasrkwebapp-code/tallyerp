<?php

namespace Tests\Feature\Inventory;

use App\Models\Account;
use App\Models\AccountGroup;
use App\Models\BomComponent;
use App\Models\Godown;
use App\Models\Invoice;
use App\Models\Party;
use App\Models\StockItem;
use App\Models\StockJournal;
use App\Models\Unit;
use App\Services\Billing\InvoiceService;
use App\Services\Inventory\BillOfMaterials;
use App\Services\Inventory\StockLedger;
use App\Services\Reports\FinancialStatements;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class FifoBomTest extends TestCase
{
    use RefreshDatabase;

    protected $owner;
    protected $company;
    protected Account $customer;
    protected Account $supplier;
    protected StockItem $widget;
    protected Godown $main;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->owner, $this->company] = $this->createCompanyWithOwner();
        $this->customer = $this->party('Mumbai Retail', 'sundry_debtors');
        $this->supplier = $this->party('Pune Supplies', 'sundry_creditors');
        $this->main = Godown::query()->where('is_default', true)->firstOrFail();

        // Opening stock 10 @ 100, valued first in, first out.
        $this->widget = $this->item('Widget', 'Nos', '10', '1000', ['costing_method' => 'fifo', 'purchase_rate' => '120']);
    }

    protected function item(string $name, string $unit, string $qty = '0', string $value = '0', array $extra = []): StockItem
    {
        return StockItem::create(array_merge([
            'name' => $name, 'unit_id' => Unit::where('symbol', $unit)->value('id'),
            'sales_account_id' => Account::idFor('sales'), 'purchase_account_id' => Account::idFor('purchase'),
            'opening_quantity' => $qty, 'opening_value' => $value, 'opening_godown_id' => $this->main->id,
        ], $extra))->load('unit');
    }

    protected function party(string $name, string $group): Account
    {
        $ledger = Account::create(['name' => $name, 'account_group_id' => AccountGroup::idFor($group), 'account_type' => 'party', 'maintain_bill_wise' => true]);
        Party::create(['account_id' => $ledger->id, 'state' => 'Maharashtra', 'gst_registration_type' => 'regular']);

        return $ledger->fresh();
    }

    protected function invoice(string $kind, string $date, string $qty, string $rate): Invoice
    {
        return app(InvoiceService::class)->save(null, $kind, [
            'date' => $date,
            'party_account_id' => (in_array($kind, ['sales', 'credit_note'], true) ? $this->customer : $this->supplier)->id,
            'items' => [['stock_item_id' => $this->widget->id, 'quantity' => $qty, 'rate' => $rate, 'gst_rate' => '0']],
        ], $this->owner);
    }

    protected function position(string $asOf): object
    {
        return app(StockLedger::class)->position($asOf)[$this->widget->id];
    }

    public function test_fifo_values_stock_at_the_latest_lots(): void
    {
        $this->invoice('purchase', '2026-05-01', '10', '120');
        $this->invoice('sales', '2026-05-10', '15', '150');      // uses 10 @ 100 and 5 @ 120
        $p = $this->position('2026-05-31');
        $this->assertSame([5000, 60000, 12000], [$p->quantity, $p->value, $p->rate]); // 5 @ 120 (average would be 550)

        $ledger = app(StockLedger::class);
        $this->assertSame(36000, $ledger->issueCost($this->widget, 3000, '2026-05-31'));
        $this->invoice('purchase', '2026-06-01', '10', '130');
        $this->assertSame(99000, $ledger->issueCost($this->widget, 8000, '2026-06-01')); // 5 @ 120 + 3 @ 130

        $this->invoice('credit_note', '2026-06-05', '1', '150');  // back at the cost of the last issue, 120
        $this->assertSame([16000, 202000], [$this->position('2026-06-05')->quantity, $this->position('2026-06-05')->value]);
        $this->invoice('debit_note', '2026-06-10', '2', '120');   // out of the oldest lot
        $this->assertSame([14000, 178000], [$this->position('2026-06-10')->quantity, $this->position('2026-06-10')->value]);

        // Selling more than is in hand: the shortfall is valued at the last cost, then filled by the next purchase.
        $this->invoice('sales', '2026-06-20', '20', '150');
        $this->assertSame([-6000, -72000], [$this->position('2026-06-20')->quantity, $this->position('2026-06-20')->value]);
        $this->invoice('purchase', '2026-06-25', '10', '140');
        $this->assertSame([4000, 56000], [$this->position('2026-06-25')->quantity, $this->position('2026-06-25')->value]);

        // Earlier dates are unaffected, and the P&L uses the FIFO closing stock.
        $this->assertSame(100000, $this->position('2026-04-30')->value);
        $this->assertSame(60000, app(FinancialStatements::class)->profitAndLoss('2026-04-01', '2026-05-31')['closing_stock']);

        // Switching to weighted average revalues the same movements.
        $this->widget->update(['costing_method' => 'average']);
        $this->assertSame(55000, app(StockLedger::class)->position('2026-05-31')[$this->widget->id]->value);
    }

    public function test_transfers_do_not_change_fifo_cost(): void
    {
        $store = Godown::create(['name' => 'Store']);
        $this->invoice('purchase', '2026-05-01', '10', '120');
        app(\App\Services\Inventory\StockJournalService::class)->create([
            'date' => '2026-05-02', 'purpose' => 'transfer', 'from_godown_id' => $this->main->id, 'to_godown_id' => $store->id,
            'lines' => [['stock_item_id' => $this->widget->id, 'quantity' => '12']],
        ], $this->owner);
        $this->assertSame(220000, $this->position('2026-05-02')->value);
        // 12 of 20 units are in the store: 12/20 of the value.
        $this->assertSame(132000, app(StockLedger::class)->position('2026-05-02', null, $store->id)[$this->widget->id]->value);
    }

    public function test_manufacture_from_a_bill_of_materials(): void
    {
        $table = $this->item('Table', 'Nos');
        $wood = $this->item('Teak Wood', 'Kg', '100', '5000');                                   // 50 a kg, average
        $screws = $this->item('Screws', 'Nos', '100', '200', ['costing_method' => 'fifo']);      // 2 each
        $boms = app(BillOfMaterials::class);

        // 2 tables take 15.5 kg of wood and 25 screws.
        $boms->save($table, ['bom_quantity' => '2', 'components' => [
            ['component_id' => $wood->id, 'quantity' => '15.5'],
            ['component_id' => $screws->id, 'quantity' => '25'],
            ['component_id' => null, 'quantity' => ''],
        ]]);
        $this->assertSame(2, $table->bomComponents()->count());

        $needs = $boms->requirements($table->fresh(), 3000, '2026-05-01');
        $this->assertSame([23250, 38000], array_map(fn ($n) => $n->quantity, $needs)); // 37.5 screws rounded up to 38
        $this->assertSame([116250, 7600], array_map(fn ($n) => $n->cost, $needs));

        $journal = $boms->manufacture($table->fresh(), ['date' => '2026-05-01', 'quantity' => '3', 'godown_id' => $this->main->id, 'other_cost' => '300'], $this->owner);
        $this->assertSame('production', $journal->purpose);
        $lines = $journal->lines->map(fn ($l) => [$l->item->name, $l->direction, $l->quantity, $l->rate, $l->value])->all();
        // (1,162.50 + 76 + 300) ÷ 3 = 512.83 a table
        $this->assertSame([
            ['Teak Wood', 'out', '23.250', '50.00', '0.00'],
            ['Screws', 'out', '38.000', '2.00', '0.00'],
            ['Table', 'in', '3.000', '512.83', '1538.49'],
        ], $lines);
        $positions = app(StockLedger::class)->position('2026-05-01');
        $this->assertSame([3000, 153849], [$positions[$table->id]->quantity, $positions[$table->id]->value]);
        $this->assertSame([76750, 383750], [$positions[$wood->id]->quantity, $positions[$wood->id]->value]);
        $this->assertSame([62000, 12400], [$positions[$screws->id]->quantity, $positions[$screws->id]->value]);
    }

    public function test_bill_of_materials_validation(): void
    {
        $table = $this->item('Table', 'Nos');
        $wood = $this->item('Teak Wood', 'Kg', '100', '5000');
        $boms = app(BillOfMaterials::class);
        $errors = function (callable $fn) {
            try {
                $fn();
            } catch (ValidationException $e) {
                return array_keys($e->errors());
            }
            $this->fail('Expected validation errors');
        };

        $this->assertSame(['bom_quantity', 'components.0.component_id', 'components.2.component_id', 'components.3.quantity'], $errors(fn () => $boms->save($table, ['bom_quantity' => '1.5', 'components' => [
            ['component_id' => $table->id, 'quantity' => '1'],           // itself
            ['component_id' => $wood->id, 'quantity' => '2'],
            ['component_id' => $wood->id, 'quantity' => '3'],            // twice
            ['component_id' => $this->widget->id, 'quantity' => '0.5'],  // Nos has no decimals
        ]])));
        $this->assertSame(['components'], $errors(fn () => $boms->save($table, ['bom_quantity' => '1', 'components' => []])));

        $boms->save($table, ['bom_quantity' => '1', 'components' => [['component_id' => $wood->id, 'quantity' => '2']]]);
        $this->assertSame(['components.0.component_id'], $errors(fn () => $boms->save($wood, ['bom_quantity' => '1', 'components' => [['component_id' => $table->id, 'quantity' => '1']]])));

        $wood->update(['tracking' => 'batch']);
        $this->assertSame(['quantity'], $errors(fn () => $boms->manufacture($table->fresh(), ['date' => '2026-05-01', 'quantity' => '1', 'godown_id' => $this->main->id], $this->owner)));
        $this->assertSame(['quantity'], $errors(fn () => $boms->manufacture($this->widget->fresh(), ['date' => '2026-05-01', 'quantity' => '1', 'godown_id' => $this->main->id], $this->owner)));
    }

    public function test_screens_and_permissions(): void
    {
        $table = $this->item('Table', 'Nos');
        $wood = $this->item('Teak Wood', 'Kg', '100', '5000');
        $this->actingAs($this->owner);

        $this->post(route('stock-items.store'), ['name' => 'Gadget', 'unit_id' => Unit::where('symbol', 'Nos')->value('id'), 'opening_quantity' => '0', 'costing_method' => 'fifo', 'is_active' => 1])->assertRedirect();
        $this->assertSame('fifo', StockItem::where('name', 'Gadget')->value('costing_method'));
        $this->get(route('stock-items.edit', $this->widget))->assertOk()->assertSee('Costing method');
        $this->get(route('stock-items.show', $this->widget))->assertOk()->assertSee('FIFO cost');

        $this->get(route('stock-items.manufacture', $table))->assertRedirect(route('stock-items.bom.edit', $table));
        $this->get(route('stock-items.bom.edit', $table))->assertOk();
        $this->put(route('stock-items.bom.update', $table), ['bom_quantity' => '1', 'components' => [['component_id' => $wood->id, 'quantity' => '7.5']]])
            ->assertRedirect(route('stock-items.show', $table));
        $this->get(route('stock-items.show', $table))->assertOk()->assertSee('Teak Wood')->assertSee('Manufacture');
        $this->get(route('stock-items.show', $wood))->assertOk()->assertSee('Used in');
        $this->get(route('stock-items.manufacture', [$table, 'quantity' => '2', 'date' => '2026-05-01']))->assertOk()->assertSee('15 Kg')->assertSee('750.00');
        $this->post(route('stock-items.manufacture.store', $table), ['date' => '2026-05-01', 'quantity' => '2', 'godown_id' => $this->main->id])
            ->assertRedirect()->assertSessionHas('success');
        $this->assertSame(1, StockJournal::where('purpose', 'production')->count());
        $this->delete(route('stock-items.destroy', $wood))->assertSessionHas('error');

        $viewer = $this->addMember($this->company, 'viewer');
        $this->actingAs($viewer)->get(route('stock-items.show', $table))->assertOk();
        $this->get(route('stock-items.bom.edit', $table))->assertForbidden();
        $this->post(route('stock-items.manufacture.store', $table), [])->assertForbidden();

        $this->actingAs($this->owner)->delete(route('stock-items.bom.destroy', $table))->assertRedirect();
        $this->assertSame(0, BomComponent::count());
    }
}
