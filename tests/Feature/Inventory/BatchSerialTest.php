<?php

namespace Tests\Feature\Inventory;

use App\Models\Account;
use App\Models\AccountGroup;
use App\Models\Godown;
use App\Models\Invoice;
use App\Models\Party;
use App\Models\StockItem;
use App\Models\Unit;
use App\Services\Billing\InvoiceService;
use App\Services\Inventory\BatchTracker;
use App\Services\Inventory\StockJournalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class BatchSerialTest extends TestCase
{
    use RefreshDatabase;

    protected $owner;
    protected $company;
    protected Account $customer;
    protected Account $supplier;
    protected StockItem $syrup;
    protected StockItem $phone;
    protected Godown $main;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->owner, $this->company] = $this->createCompanyWithOwner();
        $this->customer = $this->party('City Pharmacy', 'sundry_debtors');
        $this->supplier = $this->party('Pharma Distributors', 'sundry_creditors');
        $this->main = Godown::query()->where('is_default', true)->firstOrFail();
        $nos = Unit::where('symbol', 'Nos')->value('id');
        $ledgers = ['sales_account_id' => Account::idFor('sales'), 'purchase_account_id' => Account::idFor('purchase')];

        $this->syrup = StockItem::create(['name' => 'Cough Syrup', 'unit_id' => $nos, 'tracking' => 'batch', 'track_expiry' => true] + $ledgers);
        $this->phone = StockItem::create(['name' => 'Phone', 'unit_id' => $nos, 'tracking' => 'serial'] + $ledgers);
    }

    protected function party(string $name, string $group): Account
    {
        $ledger = Account::create(['name' => $name, 'account_group_id' => AccountGroup::idFor($group), 'account_type' => 'party', 'maintain_bill_wise' => true]);
        Party::create(['account_id' => $ledger->id, 'state' => 'Maharashtra', 'gst_registration_type' => 'regular']);

        return $ledger->fresh();
    }

    protected function invoice(string $kind, string $date, StockItem $item, string $qty, array $extra = [], ?Invoice $existing = null): Invoice
    {
        $party = in_array($kind, ['sales', 'credit_note'], true) ? $this->customer : $this->supplier;

        return app(InvoiceService::class)->save($existing, $kind, [
            'date' => $date,
            'party_account_id' => $party->id,
            'items' => [array_merge(['stock_item_id' => $item->id, 'quantity' => $qty, 'rate' => '100', 'gst_rate' => '0'], $extra)],
        ], $this->owner, 'post', $existing ? 'Correction' : null);
    }

    protected function errors(callable $fn): array
    {
        try {
            $fn();
        } catch (ValidationException $e) {
            return $e->errors();
        }
        $this->fail('Expected a validation error.');
    }

    protected function batchQty(string $batch, ?string $asOf = null, ?int $godown = null): int
    {
        return app(BatchTracker::class)->batches($asOf, [$this->syrup->id])
            ->where('batch_no', $batch)->where('godown_id', $godown ?? $this->main->id)->sum('quantity');
    }

    public function test_purchases_need_a_batch_and_expiry_and_sales_draw_from_the_batch(): void
    {
        $this->assertArrayHasKey('items.0.batch_no', $this->errors(fn () => $this->invoice('purchase', '2026-05-01', $this->syrup, '10')));
        $this->assertArrayHasKey('items.0.expiry_date', $this->errors(fn () => $this->invoice('purchase', '2026-05-01', $this->syrup, '10', ['batch_no' => 'b-101'])));
        $this->assertArrayHasKey('items.0.expiry_date', $this->errors(fn () => $this->invoice('purchase', '2026-05-01', $this->syrup, '10',
            ['batch_no' => 'B101', 'mfg_date' => '2026-06-01', 'expiry_date' => '2026-05-01'])));

        $bill = $this->invoice('purchase', '2026-05-01', $this->syrup, '10', ['batch_no' => ' b-101 ', 'mfg_date' => '2026-04-01', 'expiry_date' => '2027-03-31']);
        $this->assertSame('B-101', $bill->items->first()->batch_no); // normalised
        $this->assertSame('2027-03-31', $bill->items->first()->expiry_date->toDateString());
        $this->assertSame(10000, $this->batchQty('B-101'));

        // A later purchase of the same batch keeps its expiry; a different expiry is refused.
        $this->assertArrayHasKey('items.0.expiry_date', $this->errors(fn () => $this->invoice('purchase', '2026-05-05', $this->syrup, '5',
            ['batch_no' => 'B-101', 'expiry_date' => '2027-06-30'])));
        $this->invoice('purchase', '2026-05-05', $this->syrup, '5', ['batch_no' => 'B-101']);
        $this->assertSame(15000, $this->batchQty('B-101'));

        $sale = $this->invoice('sales', '2026-05-10', $this->syrup, '4', ['batch_no' => 'b-101']);
        $this->assertSame('2027-03-31', $sale->items->first()->expiry_date->toDateString()); // copied from the batch
        $this->assertSame(11000, $this->batchQty('B-101'));

        // Not more than the batch holds, not before it arrived, not an unknown batch.
        $this->assertStringContainsString('only 11', $this->errors(fn () => $this->invoice('sales', '2026-05-11', $this->syrup, '12', ['batch_no' => 'B-101']))['items.0.batch_no'][0]);
        $this->assertArrayHasKey('items.0.batch_no', $this->errors(fn () => $this->invoice('sales', '2026-04-20', $this->syrup, '1', ['batch_no' => 'B-101'])));
        $this->assertStringContainsString('not in this godown', $this->errors(fn () => $this->invoice('sales', '2026-05-11', $this->syrup, '1', ['batch_no' => 'X-1']))['items.0.batch_no'][0]);

        // Re-saving the posted sale does not count its own quantity twice.
        $this->invoice('sales', '2026-05-10', $this->syrup, '15', ['batch_no' => 'B-101'], $sale);
        $this->assertSame(0, $this->batchQty('B-101'));
    }

    public function test_expired_batches_cannot_be_sold_but_can_be_written_off(): void
    {
        $this->invoice('purchase', '2026-04-05', $this->syrup, '6', ['batch_no' => 'OLD', 'expiry_date' => '2026-05-31']);

        $this->assertStringContainsString('expired', $this->errors(fn () => $this->invoice('sales', '2026-06-02', $this->syrup, '1', ['batch_no' => 'OLD']))['items.0.batch_no'][0]);
        $this->invoice('sales', '2026-05-20', $this->syrup, '1', ['batch_no' => 'OLD']); // still valid then

        app(StockJournalService::class)->create(['date' => '2026-06-02', 'purpose' => 'adjustment', 'lines' => [
            ['stock_item_id' => $this->syrup->id, 'godown_id' => $this->main->id, 'direction' => 'out', 'quantity' => '5', 'batch_no' => 'OLD'],
        ]], $this->owner);
        $this->assertSame(0, $this->batchQty('OLD'));

        $this->actingAs($this->owner)->get(route('stock-batches', ['status' => 'all', 'to' => '2026-06-01']))
            ->assertOk()->assertSee('OLD')->assertSee('Expired 1 day(s) ago');
    }

    public function test_transfers_move_batches_and_serials_between_godowns(): void
    {
        $branch = Godown::create(['name' => 'Branch']);
        $this->invoice('purchase', '2026-05-01', $this->syrup, '10', ['batch_no' => 'B1', 'expiry_date' => '2027-01-31']);
        $this->invoice('purchase', '2026-05-01', $this->phone, '2', ['serials' => "IMEI-1\nIMEI-2"]);

        app(StockJournalService::class)->create(['date' => '2026-05-03', 'purpose' => 'transfer', 'from_godown_id' => $this->main->id, 'to_godown_id' => $branch->id, 'lines' => [
            ['stock_item_id' => $this->syrup->id, 'quantity' => '4', 'batch_no' => 'B1'],
            ['stock_item_id' => $this->phone->id, 'quantity' => '1', 'serials' => 'imei-2'],
        ]], $this->owner);

        $this->assertSame(6000, $this->batchQty('B1'));
        $this->assertSame(4000, $this->batchQty('B1', null, $branch->id));
        $branchBatch = app(BatchTracker::class)->batches(null, [$this->syrup->id])->firstWhere('godown_id', $branch->id);
        $this->assertSame('2027-01-31', $branchBatch->expiry_date); // the batch keeps its expiry

        $states = app(BatchTracker::class)->serialStates([$this->phone->id])[$this->phone->id];
        $this->assertSame($branch->id, $states['IMEI-2']->godown_id);
        $this->assertSame($this->main->id, $states['IMEI-1']->godown_id);

        // Selling IMEI-2 from the main godown is refused: it is at the branch.
        $this->assertStringContainsString('another godown', $this->errors(fn () => $this->invoice('sales', '2026-05-04', $this->phone, '1', ['serials' => 'IMEI-2']))['items.0.serials'][0]);
        $this->invoice('sales', '2026-05-04', $this->phone, '1', ['serials' => 'IMEI-2', 'godown_id' => $branch->id]);
    }

    public function test_serial_numbers_are_one_per_unit_and_follow_each_unit(): void
    {
        $this->assertStringContainsString('2 serial number(s)', $this->errors(fn () => $this->invoice('purchase', '2026-05-01', $this->phone, '2', ['serials' => 'SN1']))['items.0.serials'][0]);
        $this->assertArrayHasKey('items.0.quantity', $this->errors(fn () => $this->invoice('purchase', '2026-05-01', $this->phone, '1.5', ['serials' => 'SN1'])));
        $this->assertStringContainsString('twice', $this->errors(fn () => $this->invoice('purchase', '2026-05-01', $this->phone, '2', ['serials' => 'SN1, sn1']))['items.0.serials'][0]);

        $this->invoice('purchase', '2026-05-01', $this->phone, '3', ['serials' => "sn1\nSN2, SN3"]);
        $this->assertStringContainsString('already in stock', $this->errors(fn () => $this->invoice('purchase', '2026-05-02', $this->phone, '1', ['serials' => 'SN2']))['items.0.serials'][0]);

        $sale = $this->invoice('sales', '2026-05-05', $this->phone, '1', ['serials' => 'sn2']);
        $this->assertSame(['SN2'], $sale->items->first()->serialList());
        $this->assertStringContainsString('not in stock', $this->errors(fn () => $this->invoice('sales', '2026-05-06', $this->phone, '1', ['serials' => 'SN2']))['items.0.serials'][0]);
        $this->assertStringContainsString('not in stock', $this->errors(fn () => $this->invoice('sales', '2026-05-06', $this->phone, '1', ['serials' => 'SN9']))['items.0.serials'][0]);

        // The customer returns it; it can be sold again.
        $this->invoice('credit_note', '2026-05-08', $this->phone, '1', ['serials' => 'SN2']);
        $this->invoice('sales', '2026-05-09', $this->phone, '1', ['serials' => 'SN2']);

        $history = app(BatchTracker::class)->serialHistory('sn2');
        $this->assertSame(['purchase', 'sales', 'sales_return', 'sales'], $history->pluck('kind')->all());

        $this->actingAs($this->owner)->get(route('stock-serials', ['q' => 'SN2']))
            ->assertOk()->assertSee('History of SN2')->assertSee('Sales return');
        $this->actingAs($this->owner)->get(route('stock-serials'))->assertOk()->assertSee('SN1')->assertSee('SN3')->assertDontSee('SN2</a>', false);
        $csv = $this->actingAs($this->owner)->get(route('stock-serials', ['status' => 'all', 'export' => 'csv']))->streamedContent();
        $this->assertStringContainsString('SN2', $csv);
    }

    public function test_cancelling_a_sale_puts_the_serial_back(): void
    {
        $this->invoice('purchase', '2026-05-01', $this->phone, '1', ['serials' => 'Z1']);
        $sale = $this->invoice('sales', '2026-05-02', $this->phone, '1', ['serials' => 'Z1']);
        app(InvoiceService::class)->cancel($sale, $this->owner, 'Wrong customer');

        $this->assertTrue(app(BatchTracker::class)->serialStates([$this->phone->id])[$this->phone->id]['Z1']->in_stock);
    }

    public function test_stock_item_form_sets_tracking_and_opening_batches(): void
    {
        $nos = Unit::where('symbol', 'Nos')->value('id');
        $base = ['unit_id' => $nos, 'sales_account_id' => Account::idFor('sales'), 'opening_quantity' => '5', 'opening_rate' => '10', 'opening_godown_id' => $this->main->id, 'is_active' => 1];

        $this->actingAs($this->owner)->post(route('stock-items.store'), $base + ['name' => 'Vaccine', 'tracking' => 'batch', 'track_expiry' => 1])
            ->assertSessionHasErrors('opening_batch_no');
        $this->actingAs($this->owner)->post(route('stock-items.store'), $base + ['name' => 'Vaccine', 'tracking' => 'batch', 'track_expiry' => 1, 'opening_batch_no' => 'V1'])
            ->assertSessionHasErrors('opening_expiry_date');
        $this->actingAs($this->owner)->post(route('stock-items.store'), $base + ['name' => 'Vaccine', 'tracking' => 'batch', 'track_expiry' => 1,
            'opening_batch_no' => 'v1', 'opening_expiry_date' => '2027-12-31'])->assertSessionHasNoErrors();
        $vaccine = StockItem::where('name', 'Vaccine')->firstOrFail();
        $this->assertSame('V1', $vaccine->opening_batch_no);
        $this->assertSame(5000, app(BatchTracker::class)->batches(null, [$vaccine->id])->firstWhere('batch_no', 'V1')->quantity);

        $this->actingAs($this->owner)->post(route('stock-items.store'), $base + ['name' => 'Laptop', 'tracking' => 'serial', 'opening_serials' => 'L1, L2'])
            ->assertSessionHasErrors('opening_serials');
        $this->actingAs($this->owner)->post(route('stock-items.store'), $base + ['name' => 'Laptop', 'tracking' => 'serial', 'opening_serials' => "L1\nL2\nL3\nL4\nL5"])
            ->assertSessionHasNoErrors();
        $laptop = StockItem::where('name', 'Laptop')->firstOrFail();
        $this->invoice('sales', '2026-05-02', $laptop, '1', ['serials' => 'L3']);

        // Tracking is fixed once documents move the item.
        $this->actingAs($this->owner)->put(route('stock-items.update', $laptop), $base + ['name' => 'Laptop', 'tracking' => 'none'])
            ->assertSessionHasErrors('tracking');
        // Other details can still change; the opening serials stay as they were.
        $this->actingAs($this->owner)->put(route('stock-items.update', $laptop), $base + ['name' => 'Laptop Pro', 'tracking' => 'serial', 'opening_serials' => 'changed'])
            ->assertSessionHasNoErrors();
        $this->assertSame("L1\nL2\nL3\nL4\nL5", $laptop->fresh()->opening_serials);

        $this->actingAs($this->owner)->get(route('stock-items.edit', $laptop))->assertOk()->assertSee('Fixed: documents already move this item.');
    }

    public function test_forms_and_documents_show_batches_and_serials(): void
    {
        $this->invoice('purchase', '2026-05-01', $this->syrup, '10', ['batch_no' => 'B7', 'expiry_date' => '2027-02-28']);
        $sale = $this->invoice('sales', '2026-05-02', $this->syrup, '2', ['batch_no' => 'B7']);

        $this->actingAs($this->owner)->get(route('invoices.create', ['kind' => 'sales']))->assertOk()->assertSee('"batch":"B7"', false);
        $this->actingAs($this->owner)->get(route('invoices.show', $sale))->assertOk()->assertSee('Batch B7')->assertSee('exp 28-02-2027');
        $this->actingAs($this->owner)->get(route('invoices.print', $sale))->assertOk()->assertSee('Batch B7');
        $this->actingAs($this->owner)->get(route('stock-journals.create'))->assertOk()->assertSee('Batch / serials')->assertSee('journal-batches-'.$this->syrup->id, false);
        $this->actingAs($this->owner)->get(route('stock-batches'))->assertOk()->assertSee('B7')->assertSee('8 Nos');
        $this->actingAs($this->owner)->get(route('stock-batches', ['export' => 'xlsx']))->assertOk();
    }
}
