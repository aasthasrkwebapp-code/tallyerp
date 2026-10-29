<?php

namespace Tests\Feature\Inventory;

use App\Models\Account;
use App\Models\AccountGroup;
use App\Models\Godown;
use App\Models\Party;
use App\Models\StockItem;
use App\Models\Unit;
use App\Services\Billing\InvoiceService;
use App\Services\Inventory\StockAgeing;
use App\Services\Inventory\StockJournalService;
use App\Services\Inventory\StockLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StockAgeingTest extends TestCase
{
    use RefreshDatabase;

    protected $owner;
    protected $company;
    protected StockItem $widget;
    protected Godown $main;
    protected Account $supplier;
    protected Account $customer;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->owner, $this->company] = $this->createCompanyWithOwner();
        $this->main = Godown::query()->where('is_default', true)->firstOrFail();
        $this->supplier = $this->party('Pune Supplies', 'sundry_creditors');
        $this->customer = $this->party('Mumbai Retail', 'sundry_debtors');
        // Opening 10 @ 100 on the books beginning date.
        $this->widget = StockItem::create([
            'name' => 'Widget', 'unit_id' => Unit::where('symbol', 'Nos')->value('id'),
            'sales_account_id' => Account::idFor('sales'), 'purchase_account_id' => Account::idFor('purchase'),
            'opening_quantity' => '10', 'opening_value' => '1000', 'opening_godown_id' => $this->main->id,
        ])->load('unit');
    }

    protected function party(string $name, string $group): Account
    {
        $ledger = Account::create(['name' => $name, 'account_group_id' => AccountGroup::idFor($group), 'account_type' => 'party', 'maintain_bill_wise' => true]);
        Party::create(['account_id' => $ledger->id, 'state' => 'Maharashtra', 'gst_registration_type' => 'regular']);

        return $ledger->fresh();
    }

    protected function invoice(string $kind, string $date, string $qty, string $rate): void
    {
        app(InvoiceService::class)->save(null, $kind, [
            'date' => $date,
            'party_account_id' => ($kind === 'sales' ? $this->customer : $this->supplier)->id,
            'items' => [['stock_item_id' => $this->widget->id, 'quantity' => $qty, 'rate' => $rate, 'gst_rate' => '0']],
        ], $this->owner);
    }

    public function test_stock_in_hand_is_aged_from_the_latest_receipts(): void
    {
        $begin = $this->company->fresh()->books_begin_date->toDateString();
        $asOf = \Illuminate\Support\Carbon::parse($begin)->addDays(200)->toDateString();
        $this->invoice('purchase', \Illuminate\Support\Carbon::parse($asOf)->subDays(70)->toDateString(), '5', '120');
        $this->invoice('purchase', \Illuminate\Support\Carbon::parse($asOf)->subDays(10)->toDateString(), '4', '130');
        $this->invoice('sales', \Illuminate\Support\Carbon::parse($asOf)->subDays(5)->toDateString(), '12', '150');

        // In hand 7: 4 from 10 days ago, 3 from 70 days ago; the opening lot is used up.
        $report = app(StockAgeing::class)->report($asOf);
        $row = $report['rows']->firstWhere('item.id', $this->widget->id);
        $this->assertSame(7000, $row->quantity);
        $this->assertSame([4000, 0, 3000, 0, 0], array_column($row->slabs, 0));
        $this->assertSame($row->value, array_sum(array_column($row->slabs, 1)));
        $this->assertSame(app(StockLedger::class)->position($asOf)[$this->widget->id]->value, $report['total_value']);
        $this->assertSame(70, $row->days);
        $this->assertSame(['0–30 days', '31–60 days', '61–90 days', '91–180 days', 'Over 180 days'], $report['labels']);

        // Before any sale, part of the opening stock is still in hand and is over 180 days old.
        $row = app(StockAgeing::class)->report(\Illuminate\Support\Carbon::parse($asOf)->subDays(6)->toDateString(), [90])['rows'][0];
        $this->assertSame([9000, 10000], array_column($row->slabs, 0));
        $this->assertSame($begin, $row->oldest);
    }

    public function test_opening_stock_and_godown_transfers(): void
    {
        $begin = $this->company->fresh()->books_begin_date->toDateString();
        $asOf = \Illuminate\Support\Carbon::parse($begin)->addDays(100)->toDateString();
        $store = Godown::create(['name' => 'Store 2', 'is_active' => true]);
        app(StockJournalService::class)->create([
            'date' => \Illuminate\Support\Carbon::parse($asOf)->subDays(3)->toDateString(), 'purpose' => 'transfer', 'from_godown_id' => $this->main->id, 'to_godown_id' => $store->id,
            'lines' => [['stock_item_id' => $this->widget->id, 'quantity' => '4']],
        ], $this->owner);

        // Company-wide the transfer is no receipt: all 10 are as old as the opening.
        $row = app(StockAgeing::class)->report($asOf)['rows'][0];
        $this->assertSame([0, 0, 0, 10000, 0], array_column($row->slabs, 0));
        $this->assertSame($begin, $row->oldest);
        $this->assertSame(100000, $row->value);

        // In the second godown the stock arrived 3 days ago.
        $row = app(StockAgeing::class)->report($asOf, StockAgeing::DEFAULT_SLABS, $store->id)['rows'][0];
        $this->assertSame([4000, 0, 0, 0, 0], array_column($row->slabs, 0));
        $this->assertSame(40000, $row->value);
    }

    public function test_slab_parsing(): void
    {
        $this->assertSame([30, 60, 90, 180], StockAgeing::parseSlabs(null));
        $this->assertSame([15, 45], StockAgeing::parseSlabs(' 15, 45 '));
        $this->assertNull(StockAgeing::parseSlabs('60,30'));
        $this->assertNull(StockAgeing::parseSlabs('30,abc'));
        $this->assertNull(StockAgeing::parseSlabs('0'));
        $this->assertSame(['0–15 days', 'Over 15 days'], StockAgeing::labels([15]));
    }

    public function test_page_export_and_permissions(): void
    {
        $this->actingAs($this->owner)->get(route('stock-ageing', ['slabs' => '30,90']))
            ->assertOk()->assertSee('Stock ageing')->assertSee('31–90 days')->assertSee('Widget');
        $this->actingAs($this->owner)->get(route('stock-ageing', ['slabs' => '90,30']))->assertSessionHasErrors('slabs');

        $csv = $this->actingAs($this->owner)->get(route('stock-ageing', ['export' => 'csv']));
        $csv->assertOk();
        $this->assertStringContainsString('0–30 days qty', $csv->streamedContent());

        $viewer = $this->addMember($this->company, 'viewer');
        $this->actingAs($viewer)->get(route('stock-ageing'))->assertOk();
        $this->actingAs($viewer)->get(route('stock-ageing', ['export' => 'csv']))->assertForbidden();
    }
}
