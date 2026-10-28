<?php

namespace Tests\Feature\Inventory;

use App\Models\Account;
use App\Models\AccountGroup;
use App\Models\Godown;
use App\Models\Invoice;
use App\Models\Party;
use App\Models\StockItem;
use App\Models\StockJournal;
use App\Models\Unit;
use App\Services\Billing\InvoiceService;
use App\Services\Inventory\GoodsInTransit;
use App\Services\Inventory\StockJournalService;
use App\Services\Inventory\StockLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PurchaseInTransitTest extends TestCase
{
    use RefreshDatabase;

    protected $owner;
    protected $company;
    protected Godown $main;
    protected Godown $branch;
    protected StockItem $widget;
    protected StockItem $syrup;
    protected Account $supplier;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->owner, $this->company] = $this->createCompanyWithOwner();
        $this->main = Godown::query()->where('is_default', true)->firstOrFail();
        $this->branch = Godown::create(['name' => 'Branch']);
        $nos = Unit::where('symbol', 'Nos')->value('id');
        $ledgers = ['sales_account_id' => Account::idFor('sales'), 'purchase_account_id' => Account::idFor('purchase')];
        // 100 @ 10 = 1,000.
        $this->widget = StockItem::create(['name' => 'Widget', 'unit_id' => $nos, 'opening_quantity' => '100', 'opening_value' => '1000', 'opening_godown_id' => $this->main->id] + $ledgers);
        $this->syrup = StockItem::create(['name' => 'Cough Syrup', 'unit_id' => $nos, 'tracking' => 'batch'] + $ledgers);
        $this->supplier = Account::create(['name' => 'Pune Supplies', 'account_group_id' => AccountGroup::idFor('sundry_creditors'), 'account_type' => 'party', 'maintain_bill_wise' => true]);
        Party::create(['account_id' => $this->supplier->id, 'state' => 'Maharashtra', 'gst_registration_type' => 'unregistered']);
    }

    protected function bill(bool $inTransit = true, ?Invoice $invoice = null, string $widgetQty = '40'): Invoice
    {
        return app(InvoiceService::class)->save($invoice, 'purchase', [
            'date' => '2026-05-02', 'party_account_id' => $this->supplier->id, 'reference' => 'PS-1', 'in_transit' => $inTransit,
            'items' => [
                ['stock_item_id' => $this->widget->id, 'godown_id' => $this->main->id, 'quantity' => $widgetQty, 'rate' => '12', 'gst_rate' => '0'],
                ['stock_item_id' => $this->syrup->id, 'godown_id' => $this->branch->id, 'quantity' => '10', 'rate' => '20', 'gst_rate' => '0', 'batch_no' => 'B2'],
                ['account_id' => Account::idFor('purchase'), 'description' => 'Packing charges', 'quantity' => '1', 'rate' => '50', 'gst_rate' => '0'],
            ],
        ], $this->owner, 'post', 'Correction');
    }

    protected function qty(StockItem $item, Godown $godown): int
    {
        return app(StockLedger::class)->godownQuantities(null, [$item->id])[$item->id][$godown->id] ?? 0;
    }

    /** @return list<int> pending quantities in thousandths, line by line */
    protected function pending(Invoice $bill): array
    {
        return app(GoodsInTransit::class)->invoiceLines($bill->fresh())->pluck('pending')->all();
    }

    protected function refused(callable $fn, string $needle): void
    {
        try {
            $fn();
            $this->fail('Not refused: '.$needle);
        } catch (ValidationException $e) {
            $this->assertStringContainsString($needle, implode(' ', array_merge(...array_values($e->errors()))));
        }
    }

    public function test_bill_in_transit_received_in_parts_with_a_shortage(): void
    {
        $bill = $this->bill();
        $transit = Godown::where('is_transit', true)->firstOrFail();
        $this->assertTrue($bill->in_transit);
        [$w, $s, $service] = $bill->items->all();
        $this->assertSame([$transit->id, $this->main->id], [$w->godown_id, $w->transit_to_godown_id]);
        $this->assertSame([$transit->id, $this->branch->id], [$s->godown_id, $s->transit_to_godown_id]);
        $this->assertNull($service->transit_to_godown_id);

        // The goods are bought and in stock at cost, but in neither godown.
        $this->assertSame([100000, 40000, 0], [$this->qty($this->widget, $this->main), $this->qty($this->widget, $transit), $this->qty($this->syrup, $this->branch)]);
        $this->assertSame(148000, app(StockLedger::class)->position(null, collect([$this->widget->fresh('unit')]))[$this->widget->id]->value);
        $report = app(GoodsInTransit::class)->pending('2026-05-03');
        $this->assertSame(['Purchase'], $report['rows']->pluck('kind')->all());
        $this->assertSame(['Pune Supplies', 'Main Location'], [$report['rows'][0]->lines[0]->from, $report['rows'][0]->lines[0]->to]);
        // Valued at the bill's own rate, not the item's average cost.
        $this->assertSame(48000 + 20000, $report['total']);
        $this->assertSame([], app(GoodsInTransit::class)->pending('2026-05-01')['rows']->all());

        $receive = fn (array $lines, string $date = '2026-05-05') => app(GoodsInTransit::class)->receiveInvoice($bill->fresh(), ['date' => $date, 'lines' => $lines], $this->owner);
        $this->refused(fn () => $receive([$w->id => ['received' => '41']]), 'only 40 Nos is still in transit');
        $this->refused(fn () => $receive([$w->id => ['received' => '1']], '2026-05-01'), 'before the bill date');
        $this->refused(fn () => $receive([]), 'at least one line');

        // Part: 30 widgets in, 5 short; the syrup in full to the branch.
        [$receipts, $shortage] = $receive([$w->id => ['received' => '30', 'short' => '5'], $s->id => ['received' => '10']]);
        $this->assertCount(2, $receipts);
        $this->assertSame([$this->main->id, $this->branch->id], $receipts->map(fn ($r) => $r->lines->firstWhere('direction', 'in')->godown_id)->all());
        $this->assertSame($bill->id, $shortage->dispatch_invoice_id);
        $this->assertSame([5000, 0], $this->pending($bill));
        $this->assertSame([130000, 5000], [$this->qty($this->widget, $this->main), $this->qty($this->widget, $transit)]);
        $this->assertSame(10000, $this->qty($this->syrup, $this->branch));
        $this->assertSame(['B2', 'B2'], $receipts[1]->lines->pluck('batch_no')->all()); // the batch keeps its number

        // A bill with goods received can't be altered or cancelled until the receipts are cancelled.
        $this->refused(fn () => $this->bill(true, $bill->fresh()), 'Cancel those stock journals first');
        $this->refused(fn () => app(InvoiceService::class)->cancel($bill->fresh(), $this->owner, 'x'), 'Cancel those stock journals first');

        // Cancelling a receipt puts its goods back in transit.
        app(StockJournalService::class)->cancel($receipts[0]->fresh(), $this->owner, 'Wrong quantity');
        $this->assertSame([35000, 0], $this->pending($bill));
        $receive([$w->id => ['received' => '35']]);
        $this->assertSame([0, 0], $this->pending($bill));
        $this->assertSame([135000, 0], [$this->qty($this->widget, $this->main), $this->qty($this->widget, $transit)]);
        $this->assertSame([], app(GoodsInTransit::class)->pending('2026-05-06')['rows']->all());

        // With every receipt cancelled the bill can be altered again, here to arrive straight away.
        StockJournal::where('dispatch_invoice_id', $bill->id)->where('status', 'posted')->get()
            ->each(fn ($j) => app(StockJournalService::class)->cancel($j, $this->owner, 'Undo'));
        $bill = $this->bill(false, $bill->fresh(), '45');
        $this->assertFalse($bill->in_transit);
        $this->assertSame([145000, 0], [$this->qty($this->widget, $this->main), $this->qty($this->widget, $transit)]);
        $this->refused(fn () => app(GoodsInTransit::class)->receiveInvoice($bill, ['date' => '2026-05-05', 'lines' => []], $this->owner), 'Only a posted purchase bill with goods in transit');
    }

    public function test_rules_for_ticking_goods_in_transit(): void
    {
        // A bill without stock lines can't be in transit.
        $this->refused(fn () => app(InvoiceService::class)->save(null, 'purchase', [
            'date' => '2026-05-02', 'party_account_id' => $this->supplier->id, 'in_transit' => true,
            'items' => [['account_id' => Account::idFor('purchase'), 'description' => 'Repairs', 'quantity' => '1', 'rate' => '500', 'gst_rate' => '0']],
        ], $this->owner), 'at least one stock item');

        // The transit godown can't be chosen on a line directly.
        $transit = Godown::transit();
        $this->refused(fn () => app(InvoiceService::class)->save(null, 'purchase', [
            'date' => '2026-05-02', 'party_account_id' => $this->supplier->id,
            'items' => [['stock_item_id' => $this->widget->id, 'godown_id' => $transit->id, 'quantity' => '1', 'rate' => '5', 'gst_rate' => '0']],
        ], $this->owner), 'choose a godown');

        // A draft keeps the tick; the stock moves when it is posted.
        $draft = app(InvoiceService::class)->save(null, 'purchase', [
            'date' => '2026-05-02', 'party_account_id' => $this->supplier->id, 'in_transit' => true,
            'items' => [['stock_item_id' => $this->widget->id, 'godown_id' => $this->branch->id, 'quantity' => '5', 'rate' => '5', 'gst_rate' => '0']],
        ], $this->owner, 'draft');
        $this->assertTrue($draft->in_transit);
        $this->assertSame(0, $this->qty($this->widget, $transit));
        $this->assertTrue(app(InvoiceService::class)->toInput($draft)['in_transit']);
        $this->assertSame($this->branch->id, app(InvoiceService::class)->toInput($draft)['items'][0]['godown_id']);
        $posted = app(InvoiceService::class)->post($draft, $this->owner);
        $this->assertSame(5000, $this->qty($this->widget, $transit));
        $this->assertSame($this->branch->id, $posted->items[0]->transit_to_godown_id);
    }

    public function test_screens_and_permissions(): void
    {
        $bill = $this->bill();
        [$w, $s] = $bill->items->all();

        $this->actingAs($this->owner)->get(route('invoices.create', ['kind' => 'purchase']))->assertOk()->assertSee('Goods in transit');
        $this->get(route('invoices.create', ['kind' => 'sales']))->assertOk()->assertDontSee('name="in_transit"', false);
        $this->get(route('invoices.show', $bill))->assertOk()->assertSee('Goods in transit')->assertSee('Receive now')->assertSee('Branch');
        $this->get(route('stock-transit'))->assertOk()->assertSee($bill->displayNumber())->assertSee('Pune Supplies');
        $csv = $this->get(route('stock-transit', ['export' => 'csv']))->streamedContent();
        $this->assertStringContainsString('Purchase,'.$bill->displayNumber().',02-05-2026,"Pune Supplies","Main Location",Widget,,40,0,40,Nos,480.00', $csv);

        $response = $this->post(route('invoices.receive-transit', $bill), ['date' => '2026-05-04', 'lines' => [$w->id => ['received' => '40'], $s->id => ['received' => '4', 'short' => '1']]]);
        $response->assertRedirect(route('invoices.show', $bill))->assertSessionHas('success');
        $receipt = StockJournal::where('dispatch_invoice_id', $bill->id)->where('purpose', 'transfer')->firstOrFail();
        $this->get(route('stock-journals.show', $receipt))->assertOk()->assertSee('bought in transit on purchase')->assertSee($bill->displayNumber());
        $this->get(route('stock-journals.index'))->assertSee('Transit receipt');
        $this->get(route('invoices.show', $bill))->assertSee($receipt->number)->assertSee('In transit');
        $this->post(route('invoices.receive-transit', $bill), ['date' => '2026-05-04', 'lines' => [$s->id => ['received' => '9']]])->assertSessionHasErrors("lines.{$s->id}.received");

        $viewer = $this->addMember($this->company, 'viewer');
        $this->actingAs($viewer)->get(route('invoices.show', $bill))->assertOk()->assertDontSee('Receive now');
        $this->actingAs($viewer)->post(route('invoices.receive-transit', $bill), ['date' => '2026-05-04', 'lines' => [$s->id => ['received' => '5']]])->assertForbidden();
    }
}
