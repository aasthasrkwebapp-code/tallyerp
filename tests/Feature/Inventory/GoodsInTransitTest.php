<?php

namespace Tests\Feature\Inventory;

use App\Models\Account;
use App\Models\Godown;
use App\Models\StockItem;
use App\Models\StockJournal;
use App\Models\Unit;
use App\Services\Inventory\BatchTracker;
use App\Services\Inventory\GoodsInTransit;
use App\Services\Inventory\StockJournalService;
use App\Services\Inventory\StockLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class GoodsInTransitTest extends TestCase
{
    use RefreshDatabase;

    protected $owner;
    protected $company;
    protected Godown $main;
    protected Godown $branch;
    protected StockItem $widget;
    protected StockItem $syrup;
    protected StockItem $phone;

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
        $this->syrup = StockItem::create(['name' => 'Cough Syrup', 'unit_id' => $nos, 'tracking' => 'batch', 'opening_quantity' => '20', 'opening_value' => '400',
            'opening_godown_id' => $this->main->id, 'opening_batch_no' => 'B1'] + $ledgers);
        $this->phone = StockItem::create(['name' => 'Phone', 'unit_id' => $nos, 'tracking' => 'serial', 'opening_quantity' => '2', 'opening_value' => '20000',
            'opening_godown_id' => $this->main->id, 'opening_serials' => "IMEI-1\nIMEI-2"] + $ledgers);
    }

    protected function dispatch(array $lines, string $date = '2026-05-02'): StockJournal
    {
        return app(StockJournalService::class)->create(['date' => $date, 'purpose' => 'transfer', 'in_transit' => true,
            'from_godown_id' => $this->main->id, 'to_godown_id' => $this->branch->id, 'lines' => $lines], $this->owner);
    }

    /** Quantity (thousandths) of an item in a godown. */
    protected function qty(StockItem $item, Godown $godown, ?string $asOf = null): int
    {
        return app(StockLedger::class)->godownQuantities($asOf, [$item->id])[$item->id][$godown->id] ?? 0;
    }

    /** @return array<int, int> line id => pending */
    protected function pending(StockJournal $dispatch): array
    {
        return app(GoodsInTransit::class)->lines($dispatch->fresh())->mapWithKeys(fn ($l) => [$l->line->id => $l->pending])->all();
    }

    public function test_dispatch_receive_part_and_write_off_shortage(): void
    {
        $dispatch = $this->dispatch([
            ['stock_item_id' => $this->widget->id, 'quantity' => '30'],
            ['stock_item_id' => $this->syrup->id, 'quantity' => '8', 'batch_no' => 'B1'],
        ]);
        $transit = Godown::where('is_transit', true)->firstOrFail();
        $this->assertSame('Goods in Transit', $transit->name);
        $this->assertSame($this->branch->id, $dispatch->transit_to_godown_id);
        $this->assertSame(70000, $this->qty($this->widget, $this->main));
        $this->assertSame(30000, $this->qty($this->widget, $transit));
        $this->assertSame(0, $this->qty($this->widget, $this->branch));
        // Still in closing stock at cost.
        $this->assertSame(100000, app(StockLedger::class)->position(null, collect([$this->widget->fresh('unit')]))[$this->widget->id]->value);

        [$widgetLine, $syrupLine] = array_keys($this->pending($dispatch));
        $report = app(GoodsInTransit::class)->pending('2026-05-02');
        $this->assertSame(30000 + 16000, $report['total']); // 30 @ 10 + 8 @ 20

        [$receipt, $shortage] = app(GoodsInTransit::class)->receive($dispatch, ['date' => '2026-05-05', 'narration' => 'LR 991', 'lines' => [
            $widgetLine => ['received' => '25', 'short' => '2'],
            $syrupLine => ['received' => '5'],
        ]], $this->owner);
        $this->assertSame($dispatch->id, $receipt->dispatch_id);
        $this->assertSame('adjustment', $shortage->purpose);
        $this->assertStringContainsString('LR 991', $receipt->narration);
        $this->assertSame(25000, $this->qty($this->widget, $this->branch));
        $this->assertSame(3000, $this->qty($this->widget, $transit));
        $this->assertSame(98000, app(StockLedger::class)->position(null, collect([$this->widget->fresh('unit')]))[$this->widget->id]->quantity);
        $this->assertSame(5000, app(BatchTracker::class)->batches(null, [$this->syrup->id])->where('batch_no', 'B1')->where('godown_id', $this->branch->id)->sum('quantity'));
        $this->assertSame([$widgetLine => 3000, $syrupLine => 3000], $this->pending($dispatch));

        // As on the dispatch date everything was still in transit.
        $this->assertSame(46000, app(GoodsInTransit::class)->pending('2026-05-04')['total']);

        // More than is in transit, and a date before dispatch, are refused.
        foreach ([[['received' => '4'], '2026-05-06', 'lines.'.$widgetLine.'.received'], [['received' => '1'], '2026-05-01', 'date']] as [$line, $date, $key]) {
            try {
                app(GoodsInTransit::class)->receive($dispatch, ['date' => $date, 'lines' => [$widgetLine => $line]], $this->owner);
                $this->fail('Accepted');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey($key, $e->errors());
            }
        }

        // The dispatch can't be cancelled while receipts stand; cancelling the receipt puts goods back in transit.
        try {
            app(StockJournalService::class)->cancel($dispatch, $this->owner, 'x');
            $this->fail('Cancelled');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('journal', $e->errors());
        }
        app(StockJournalService::class)->cancel($receipt, $this->owner, 'Wrong count');
        $this->assertSame([$widgetLine => 28000, $syrupLine => 8000], $this->pending($dispatch));

        app(GoodsInTransit::class)->receive($dispatch, ['date' => '2026-05-06', 'lines' => [$widgetLine => ['received' => '28'], $syrupLine => ['received' => '8']]], $this->owner);
        $this->assertSame([$widgetLine => 0, $syrupLine => 0], $this->pending($dispatch));
        $this->assertSame(0, app(GoodsInTransit::class)->pending('2026-05-31')['rows']->count());
        $this->assertSame(0, $this->qty($this->widget, $transit));
    }

    public function test_serials_are_received_in_full_and_transit_godown_is_kept_out_of_choices(): void
    {
        $dispatch = $this->dispatch([['stock_item_id' => $this->phone->id, 'quantity' => '2', 'serials' => "IMEI-1\nIMEI-2"]]);
        $line = array_key_first($this->pending($dispatch));
        $transit = Godown::where('is_transit', true)->firstOrFail();
        foreach ([['received' => '1'], ['received' => '1', 'short' => '1']] as $input) {
            try {
                app(GoodsInTransit::class)->receive($dispatch, ['date' => '2026-05-03', 'lines' => [$line => $input]], $this->owner);
                $this->fail('Accepted');
            } catch (ValidationException $e) {
                $this->assertStringContainsString('received in full', $e->errors()["lines.$line.received"][0]);
            }
        }
        $this->assertSame($transit->id, app(BatchTracker::class)->serialStates([$this->phone->id])[$this->phone->id]['IMEI-1']->godown_id);
        app(GoodsInTransit::class)->receive($dispatch, ['date' => '2026-05-03', 'lines' => [$line => ['received' => '2']]], $this->owner);
        $this->assertSame($this->branch->id, app(BatchTracker::class)->serialStates([$this->phone->id])[$this->phone->id]['IMEI-2']->godown_id);

        // Ordinary journals can't use the transit godown, and it isn't offered or the default.
        try {
            app(StockJournalService::class)->create(['date' => '2026-05-04', 'purpose' => 'transfer', 'from_godown_id' => $transit->id, 'to_godown_id' => $this->branch->id,
                'lines' => [['stock_item_id' => $this->widget->id, 'quantity' => '1']]], $this->owner);
            $this->fail('Accepted');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('from_godown_id', $e->errors());
        }
        $this->assertSame($this->main->id, Godown::defaultId());
        $this->actingAs($this->owner)->get(route('stock-journals.create'))->assertOk()->assertDontSee('>Goods in Transit</option>', false)->assertSee('Goods in transit');
        $this->assertSame(1, Godown::where('is_transit', true)->count()); // made once
    }

    public function test_screens_and_permissions(): void
    {
        $this->actingAs($this->owner)->post(route('stock-journals.store'), ['date' => '2026-05-02', 'purpose' => 'transfer', 'in_transit' => '1',
            'from_godown_id' => $this->main->id, 'to_godown_id' => $this->branch->id, 'lines' => [['stock_item_id' => $this->widget->id, 'quantity' => '10']]])
            ->assertRedirect()->assertSessionHas('success', fn ($m) => str_contains($m, 'in transit'));
        $dispatch = StockJournal::firstOrFail();
        $line = array_key_first($this->pending($dispatch));

        $this->get(route('stock-journals.show', $dispatch))->assertOk()->assertSee('Goods in transit to Branch')->assertSee('Receive now');
        $this->get(route('stock-transit', ['date' => '2026-05-02']))->assertOk()->assertSee($dispatch->number)->assertSee('Main Location → Branch', false)->assertSee('100.00');
        $this->assertStringContainsString('Widget', $this->get(route('stock-transit', ['date' => '2026-05-02', 'export' => 'csv']))->streamedContent());
        $this->get(route('stock-journals.index'))->assertSee('In transit');

        $viewer = $this->addMember($this->company, 'viewer');
        $this->actingAs($viewer)->get(route('stock-transit'))->assertOk();
        $this->actingAs($viewer)->get(route('stock-journals.show', $dispatch))->assertOk()->assertDontSee('Receive now');
        $this->actingAs($viewer)->post(route('stock-journals.receive', $dispatch), ['date' => '2026-05-03', 'lines' => [$line => ['received' => '10']]])->assertForbidden();

        $this->actingAs($this->owner)->post(route('stock-journals.receive', $dispatch), ['date' => '2026-05-03', 'lines' => [$line => ['received' => '9', 'short' => '1']]])
            ->assertRedirect(route('stock-journals.show', $dispatch))->assertSessionHas('success', fn ($m) => str_contains($m, 'Shortage written off'));
        $receipt = StockJournal::where('dispatch_id', $dispatch->id)->where('purpose', 'transfer')->firstOrFail();
        $this->get(route('stock-journals.show', $receipt))->assertSee('receives goods')->assertSee($dispatch->number);
        $this->get(route('stock-journals.show', $dispatch))->assertSee('Received')->assertDontSee('Receive now');
        $this->post(route('stock-journals.receive', $dispatch), ['date' => '2026-05-03', 'lines' => [$line => ['received' => '1']]])->assertSessionHasErrors();
    }
}
