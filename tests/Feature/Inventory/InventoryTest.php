<?php

namespace Tests\Feature\Inventory;

use App\Models\Account;
use App\Models\AccountGroup;
use App\Models\FinancialYear;
use App\Models\Godown;
use App\Models\Invoice;
use App\Models\Party;
use App\Models\StockGroup;
use App\Models\StockItem;
use App\Models\StockJournal;
use App\Models\Unit;
use App\Services\Billing\InvoiceService;
use App\Services\Inventory\StockJournalService;
use App\Services\Inventory\StockLedger;
use App\Services\Reports\FinancialStatements;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class InventoryTest extends TestCase
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

        // Opening stock 10 @ 100; capital brought in to match.
        $this->widget = StockItem::create([
            'name' => 'Widget', 'unit_id' => Unit::where('symbol', 'Nos')->value('id'), 'hsn_sac' => '8471', 'gst_rate' => '18',
            'sales_account_id' => Account::idFor('sales'), 'purchase_account_id' => Account::idFor('purchase'),
            'sales_rate' => '150', 'purchase_rate' => '120',
            'opening_quantity' => '10', 'opening_value' => '1000', 'opening_godown_id' => $this->main->id,
        ]);
        Account::where('system_key', 'capital')->update(['opening_balance' => 1000, 'opening_balance_type' => 'Cr']);
    }

    protected function party(string $name, string $group): Account
    {
        $ledger = Account::create(['name' => $name, 'account_group_id' => AccountGroup::idFor($group), 'account_type' => 'party', 'maintain_bill_wise' => true]);
        Party::create(['account_id' => $ledger->id, 'state' => 'Maharashtra', 'gst_registration_type' => 'regular']);

        return $ledger->fresh();
    }

    protected function invoice(string $kind, string $date, string $qty, string $rate, array $extra = [], ?Account $party = null): Invoice
    {
        $party ??= in_array($kind, ['sales', 'credit_note'], true) ? $this->customer : $this->supplier;

        return app(InvoiceService::class)->save(null, $kind, [
            'date' => $date,
            'party_account_id' => $party->id,
            'items' => [array_merge(['stock_item_id' => $this->widget->id, 'quantity' => $qty, 'rate' => $rate, 'gst_rate' => '0'], $extra)],
        ], $this->owner);
    }

    protected function position(string $asOf, ?int $godown = null): object
    {
        return app(StockLedger::class)->position($asOf, null, $godown)[$this->widget->id];
    }

    public function test_invoice_items_take_defaults_from_the_stock_item(): void
    {
        $invoice = $this->invoice('sales', '2026-05-10', '2', '150');
        $item = $invoice->items->first();

        $this->assertSame($this->widget->id, $item->stock_item_id);
        $this->assertSame($this->main->id, $item->godown_id);
        $this->assertSame(Account::idFor('sales'), $item->account_id);
        $this->assertSame('Widget', $item->description);
        $this->assertSame('Nos', $item->unit);
        $this->assertSame('8471', $item->hsn_sac);

        // The edit form gets the stock item back.
        $this->assertSame($this->widget->id, app(InvoiceService::class)->toInput($invoice)['items'][0]['stock_item_id']);
    }

    public function test_weighted_average_valuation_follows_purchases_and_returns(): void
    {
        $this->invoice('purchase', '2026-05-01', '10', '120');   // in 10 @ 120 => avg (1000 + 1200) / 20 = 110
        $this->invoice('sales', '2026-05-10', '5', '150');       // out 5
        $p = $this->position('2026-05-31');
        $this->assertSame(15000, $p->quantity);                   // 15.000
        $this->assertSame(11000, $p->rate);
        $this->assertSame(165000, $p->value);                     // 15 × 110

        $this->invoice('credit_note', '2026-06-01', '1', '150');  // sales return: quantity only
        $this->invoice('debit_note', '2026-06-02', '2', '120');   // purchase return reduces cost pool
        $p = $this->position('2026-06-30');
        $this->assertSame(14000, $p->quantity);                   // 15 + 1 − 2
        // (1000 + 1200 − 240) / (10 + 10 − 2) = 108.888…; 14 × that = 1524.44
        $this->assertSame(152444, $p->value);

        // Earlier dates are unaffected by later documents.
        $this->assertSame(100000, $this->position('2026-04-30')->value);
    }

    public function test_drafts_and_cancelled_invoices_do_not_move_stock(): void
    {
        app(InvoiceService::class)->save(null, 'sales', [
            'date' => '2026-05-10', 'party_account_id' => $this->customer->id,
            'items' => [['stock_item_id' => $this->widget->id, 'quantity' => '3', 'rate' => '150', 'gst_rate' => '0']],
        ], $this->owner, 'draft');
        $this->assertSame(10000, $this->position('2026-05-31')->quantity);

        $sale = $this->invoice('sales', '2026-05-11', '4', '150');
        $this->assertSame(6000, $this->position('2026-05-31')->quantity);
        app(InvoiceService::class)->cancel($sale, $this->owner, 'Wrong party');
        $this->assertSame(10000, $this->position('2026-05-31')->quantity);
    }

    public function test_statements_include_opening_and_closing_stock_and_balance(): void
    {
        $this->invoice('purchase', '2026-05-01', '10', '120');
        $this->invoice('sales', '2026-05-10', '5', '150');
        $statements = app(FinancialStatements::class);

        // Opening stock (Dr 1000) balances the capital (Cr 1000).
        $tb = $statements->trialBalance('2026-04-01', '2026-06-30');
        $this->assertSame(0, $tb['difference']);
        $this->assertSame(100000, $tb['openingStock']);
        $this->assertSame($tb['totals']['closing_dr'], $tb['totals']['closing_cr']);
        $this->assertSame(0, $tb['totals']['opening']);

        // Sales 750 − purchases 1200 − opening 1000 + closing 1650 = 200.
        $pl = $statements->profitAndLoss('2026-04-01', '2026-06-30');
        $this->assertSame(100000, $pl['opening_stock']);
        $this->assertSame(165000, $pl['closing_stock']);
        $this->assertSame(20000, $pl['gross_profit']);

        $bs = $statements->balanceSheet('2026-06-30');
        $this->assertSame(165000, $bs['closingStock']);
        $this->assertSame(20000, $bs['currentProfit']);
        $this->assertSame($bs['totalAssets'], $bs['totalLiabilities']);
        $this->assertSame(0, $bs['difference']);
    }

    public function test_statements_balance_in_the_next_year(): void
    {
        $this->company->update(['settings' => ['allow_future_dated_vouchers' => true]]);
        FinancialYear::open($this->company, '2027-04-01', makeCurrent: false);
        $this->invoice('purchase', '2026-05-01', '10', '120');
        $this->invoice('sales', '2026-05-10', '5', '150');      // year 1 profit 200, closing stock 1650
        // Year 2: sales 750, cost 550 (another customer: numbering restarts each year).
        $this->invoice('sales', '2027-04-10', '5', '150', [], $this->party('Nashik Stores', 'sundry_debtors'));
        $statements = app(FinancialStatements::class);

        $tb = $statements->trialBalance('2027-04-01', '2027-04-30');
        $this->assertSame(165000, $tb['openingStock']);          // last year's closing stock
        $this->assertSame(-20000, $tb['accumulated']);           // last year's profit, a credit
        $this->assertSame(0, $tb['totals']['opening']);
        $this->assertSame($tb['totals']['closing_dr'], $tb['totals']['closing_cr']);

        $pl = $statements->profitAndLoss('2027-04-01', '2027-04-30');
        $this->assertSame(165000, $pl['opening_stock']);
        $this->assertSame(110000, $pl['closing_stock']);
        $this->assertSame(20000, $pl['gross_profit']);           // 750 − 1650 + 1100

        $bs = $statements->balanceSheet('2027-04-30');
        $this->assertSame(20000, $bs['accumulatedProfit']);
        $this->assertSame(20000, $bs['currentProfit']);
        $this->assertSame($bs['totalAssets'], $bs['totalLiabilities']);
    }

    public function test_transfer_moves_quantity_between_godowns_without_changing_value(): void
    {
        $shop = Godown::create(['name' => 'Shop']);
        $journal = app(StockJournalService::class)->create([
            'date' => '2026-05-01', 'purpose' => 'transfer', 'from_godown_id' => $this->main->id, 'to_godown_id' => $shop->id,
            'lines' => [['stock_item_id' => $this->widget->id, 'quantity' => '4']],
        ], $this->owner);

        $this->assertSame('SJ/0001', $journal->number);
        $this->assertCount(2, $journal->lines);
        $this->assertSame(6000, $this->position('2026-05-31', $this->main->id)->quantity);
        $this->assertSame(4000, $this->position('2026-05-31', $shop->id)->quantity);
        $this->assertSame(10000, $this->position('2026-05-31')->quantity);
        $this->assertSame(100000, $this->position('2026-05-31')->value);

        $this->expectException(ValidationException::class);
        app(StockJournalService::class)->create([
            'date' => '2026-05-01', 'purpose' => 'transfer', 'from_godown_id' => $shop->id, 'to_godown_id' => $shop->id,
            'lines' => [['stock_item_id' => $this->widget->id, 'quantity' => '1']],
        ], $this->owner);
    }

    public function test_adjustments_change_stock_and_cancelling_restores_it(): void
    {
        $service = app(StockJournalService::class);
        $shortage = $service->create([
            'date' => '2026-05-01', 'purpose' => 'adjustment',
            'lines' => [['stock_item_id' => $this->widget->id, 'godown_id' => $this->main->id, 'direction' => 'out', 'quantity' => '2']],
        ], $this->owner);
        $this->assertSame(80000, $this->position('2026-05-31')->value); // 8 × 100

        // Excess without a rate comes in at the current average cost.
        $excess = $service->create([
            'date' => '2026-05-02', 'purpose' => 'adjustment',
            'lines' => [['stock_item_id' => $this->widget->id, 'godown_id' => $this->main->id, 'direction' => 'in', 'quantity' => '1']],
        ], $this->owner);
        $this->assertSame('100.00', $excess->lines->first()->value);
        $this->assertSame(9000, $this->position('2026-05-31')->quantity);

        $service->cancel($shortage, $this->owner, 'Found the missing stock');
        $this->assertSame('cancelled', $shortage->fresh()->status);
        $this->assertSame(11000, $this->position('2026-05-31')->quantity);

        $this->expectException(ValidationException::class);
        $service->cancel($shortage->fresh(), $this->owner, 'Again');
    }

    public function test_unit_decimal_places_are_enforced(): void
    {
        $this->expectException(ValidationException::class);
        $this->invoice('sales', '2026-05-10', '1.5', '150'); // Nos has no decimals
    }

    public function test_selling_more_than_in_stock_warns_but_posts(): void
    {
        $response = $this->actingAs($this->owner)->post(route('invoices.store', ['kind' => 'sales']), [
            'date' => '2026-05-10', 'party_account_id' => $this->customer->id,
            'items' => [['stock_item_id' => $this->widget->id, 'quantity' => '12', 'rate' => '150', 'gst_rate' => '0']],
        ]);

        $response->assertRedirect()->assertSessionHas('warning', fn ($w) => str_contains($w, 'Widget in Main Location: -2 Nos'));
        $this->assertSame(-2000, $this->position('2026-05-31')->quantity);
    }

    public function test_masters_screens_create_and_protect_records(): void
    {
        $this->actingAs($this->owner);

        $this->post(route('inventory-masters.store', 'units'), ['symbol' => 'Doz', 'name' => 'Dozen', 'decimal_places' => 0, 'uqc' => 'doz'])->assertRedirect();
        $this->assertSame('DOZ', Unit::where('symbol', 'Doz')->value('uqc'));

        $this->post(route('inventory-masters.store', 'stock-groups'), ['name' => 'Hardware'])->assertRedirect();
        $parent = StockGroup::where('name', 'Hardware')->first();
        $this->post(route('inventory-masters.store', 'stock-groups'), ['name' => 'Fasteners', 'parent_id' => $parent->id])->assertRedirect();
        $child = StockGroup::where('name', 'Fasteners')->first();
        // A group cannot move under its own sub-group.
        $this->put(route('inventory-masters.update', ['stock-groups', $parent->id]), ['name' => 'Hardware', 'parent_id' => $child->id])->assertSessionHasErrors('parent_id');

        $this->post(route('inventory-masters.store', 'godowns'), ['name' => 'Shop', 'is_active' => 1, 'is_default' => 1])->assertRedirect();
        $this->assertSame('Shop', Godown::where('is_default', true)->sole()->name);

        // In-use records cannot be deleted.
        $nos = Unit::where('symbol', 'Nos')->first();
        $this->delete(route('inventory-masters.destroy', ['units', $nos->id]))->assertSessionHas('error');
        $this->assertNotNull($nos->fresh());
        $this->delete(route('inventory-masters.destroy', ['godowns', $this->main->id]))->assertSessionHas('error');

        // Stock item: opening value from quantity × rate.
        $this->post(route('stock-items.store'), [
            'name' => 'Bolt', 'unit_id' => $nos->id, 'stock_group_id' => $child->id, 'opening_quantity' => '100', 'opening_rate' => '2.50',
            'opening_godown_id' => $this->main->id, 'reorder_level' => '150', 'is_active' => 1,
        ])->assertRedirect();
        $bolt = StockItem::where('name', 'Bolt')->first();
        $this->assertSame('250.00', $bolt->opening_value);

        $this->invoice('sales', '2026-05-10', '1', '150');
        $this->delete(route('stock-items.destroy', $this->widget))->assertSessionHas('error');
        $this->delete(route('stock-items.destroy', $bolt))->assertRedirect(route('stock-items.index'));
    }

    public function test_inventory_screens_render(): void
    {
        $this->actingAs($this->owner);
        $this->invoice('purchase', '2026-05-01', '10', '120');
        $this->widget->update(['reorder_level' => '50']);
        app(StockJournalService::class)->create([
            'date' => '2026-05-02', 'purpose' => 'adjustment', 'narration' => 'Count',
            'lines' => [['stock_item_id' => $this->widget->id, 'godown_id' => $this->main->id, 'direction' => 'out', 'quantity' => '1']],
        ], $this->owner);
        $journal = StockJournal::first();

        $this->get(route('stock-items.index'))->assertOk()->assertSee('Widget');
        $this->get(route('stock-items.create'))->assertOk();
        $this->get(route('stock-items.edit', $this->widget))->assertOk();
        $this->get(route('stock-items.show', [$this->widget, 'from' => '2026-04-01', 'to' => '2026-05-31']))->assertOk()->assertSee('Purchase')->assertSee('SJ/0001');
        foreach (array_keys(\App\Http\Controllers\Inventory\InventoryMasterController::MASTERS) as $master) {
            $this->get(route('inventory-masters.index', $master))->assertOk();
        }
        $this->get(route('stock-journals.index'))->assertOk()->assertSee('SJ/0001');
        $this->get(route('stock-journals.create', ['purpose' => 'adjustment']))->assertOk();
        $this->get(route('stock-journals.show', $journal))->assertOk()->assertSee('Count');
        $this->get(route('stock-summary', ['to' => '2026-05-31']))->assertOk()->assertSee('2,090.00'); // 19 × 110
        $this->get(route('stock-summary', ['to' => '2026-05-31', 'export' => 'csv']))->assertOk();
        $this->get(route('stock-reorder'))->assertOk()->assertSee('Widget');
        $this->get(route('invoices.create', ['kind' => 'sales']))->assertOk()->assertSee('Stock item');
        $this->get(route('reports.profit-loss', ['from' => '2026-04-01', 'to' => '2026-05-31']))->assertOk()->assertSee('Closing stock');
        $this->get(route('reports.balance-sheet', ['to' => '2026-05-31']))->assertOk()->assertSee('Closing stock');
        $this->get(route('reports.trial-balance', ['from' => '2026-04-01', 'to' => '2026-05-31']))->assertOk()->assertSee('Opening stock');
    }

    public function test_roles_limit_inventory_changes(): void
    {
        $viewer = $this->addMember($this->company, 'viewer');
        $this->actingAs($viewer);
        $this->get(route('stock-items.index'))->assertOk();
        $this->get(route('stock-items.create'))->assertForbidden();
        $this->post(route('stock-journals.store'), [])->assertForbidden();

        $storekeeper = $this->addMember($this->company, 'inventory');
        $this->actingAs($storekeeper);
        $this->get(route('stock-journals.create'))->assertOk();
    }

    public function test_other_companies_cannot_see_stock(): void
    {
        [$otherOwner] = $this->createCompanyWithOwner(['name' => 'Other Co']);
        $this->assertNull(StockItem::find($this->widget->id));
        $this->actingAs($otherOwner)->get(route('stock-items.show', $this->widget->id))->assertNotFound();
    }
}
