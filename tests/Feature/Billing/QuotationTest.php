<?php

namespace Tests\Feature\Billing;

use App\Models\Account;
use App\Models\AccountGroup;
use App\Models\AuditLog;
use App\Models\Order;
use App\Models\Party;
use App\Models\StockItem;
use App\Models\Unit;
use App\Services\Billing\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QuotationTest extends TestCase
{
    use RefreshDatabase;

    protected $owner;
    protected $company;
    protected Account $customer;
    protected StockItem $widget;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->owner, $this->company] = $this->createCompanyWithOwner();
        $this->customer = Account::create(['name' => 'Mumbai Retail', 'account_group_id' => AccountGroup::idFor('sundry_debtors'), 'account_type' => 'party', 'maintain_bill_wise' => true]);
        Party::create(['account_id' => $this->customer->id, 'state' => 'Maharashtra', 'gst_registration_type' => 'regular']);
        $this->widget = StockItem::create([
            'name' => 'Widget', 'unit_id' => Unit::where('symbol', 'Nos')->value('id'), 'hsn_sac' => '8471', 'gst_rate' => '18',
            'sales_account_id' => Account::idFor('sales'), 'purchase_account_id' => Account::idFor('purchase'), 'sales_rate' => '150',
        ]);
    }

    protected function quote(array $extra = []): Order
    {
        return app(OrderService::class)->save(null, 'quotation', array_merge([
            'date' => '2026-05-01',
            'delivery_date' => '2026-05-31',
            'party_account_id' => $this->customer->id,
            'reference' => 'ENQ-12',
            'terms' => 'Prices valid for 30 days.',
            'items' => [
                ['stock_item_id' => $this->widget->id, 'quantity' => '10', 'rate' => '150', 'gst_rate' => '18', 'discount_percent' => '5'],
                ['description' => 'Installation', 'quantity' => '1', 'rate' => '500', 'gst_rate' => '18', 'hsn_sac' => '998719'],
            ],
        ], $extra), $this->owner);
    }

    public function test_quotation_is_numbered_and_shown_with_validity(): void
    {
        $quote = $this->quote();
        $this->assertStringStartsWith('QT', $quote->number);
        $this->assertTrue($quote->isQuotation());

        $this->actingAs($this->owner)->get(route('orders.index', ['kind' => 'quotation']))->assertOk()
            ->assertSee($quote->number)->assertSee('Valid until')->assertDontSee(route('orders.pending', ['kind' => 'quotation']));
        $this->actingAs($this->owner)->get(route('orders.show', $quote))->assertOk()
            ->assertSee('Valid until')->assertSee('Convert to sales order')
            ->assertDontSee('Make sales invoice')->assertDontSee('Make delivery note');
        $this->actingAs($this->owner)->get(route('orders.pdf', $quote))->assertOk();
        $this->actingAs($this->owner)->get(route('orders.pending', ['kind' => 'quotation']))->assertNotFound();
    }

    public function test_convert_makes_a_sales_order_and_closes_the_quotation(): void
    {
        $quote = $this->quote();
        $this->actingAs($this->owner)->post(route('orders.convert', $quote), ['date' => '2026-05-10'])->assertRedirect();

        $order = Order::query()->where('kind', 'sales_order')->sole();
        $this->assertSame($quote->id, $order->source_id);
        $this->assertSame('2026-05-10', $order->date->toDateString());
        $this->assertNull($order->delivery_date);
        $this->assertSame($quote->grand_total, $order->grand_total);
        $this->assertCount(2, $order->items);
        $this->assertSame('5.00', (string) $order->items->firstWhere('stock_item_id', $this->widget->id)->discount_percent);
        $this->assertSame('Prices valid for 30 days.', $order->terms);

        $quote->refresh();
        $this->assertSame(Order::CLOSED, $quote->status);
        $this->assertSame('Converted', app(OrderService::class)->statusLabel($quote));
        $this->assertTrue(AuditLog::where('event', 'converted')->where('auditable_id', $quote->id)->exists());

        $this->actingAs($this->owner)->get(route('orders.show', $order))->assertOk()->assertSee('Made from quotation')->assertSee($quote->number)->assertSee('Make sales invoice');
        $this->actingAs($this->owner)->get(route('orders.show', $quote))->assertOk()->assertSee($order->number)->assertDontSee('Convert to sales order')->assertDontSee('Reopen');

        // Converting twice, or reopening a converted quotation, is refused.
        $this->actingAs($this->owner)->post(route('orders.convert', $quote))->assertSessionHasErrors('order');
        $this->actingAs($this->owner)->post(route('orders.reopen', $quote))->assertSessionHasErrors('order');
        $this->assertSame(1, Order::query()->where('kind', 'sales_order')->count());
    }

    public function test_quotation_cannot_be_invoiced_or_delivered_directly(): void
    {
        $quote = $this->quote();
        $this->actingAs($this->owner)->get(route('invoices.create', ['kind' => 'sales', 'order' => $quote->id]))->assertRedirect(route('orders.show', $quote));
        $this->actingAs($this->owner)->get(route('notes.create', ['kind' => 'delivery_note', 'order' => $quote->id]))->assertNotFound();

        $line = $quote->items->first();
        $this->actingAs($this->owner)->post(route('invoices.store'), [
            'kind' => 'sales', 'date' => '2026-05-02', 'party_account_id' => $this->customer->id, 'action' => 'post',
            'items' => [['order_item_id' => $line->id, 'stock_item_id' => $this->widget->id, 'account_id' => Account::idFor('sales'), 'quantity' => '1', 'rate' => '150', 'gst_rate' => '18']],
        ])->assertSessionHasErrors('items.0.order_item_id');
    }

    public function test_expired_label_and_closing_without_conversion(): void
    {
        $quote = $this->quote(['date' => '2026-04-05', 'delivery_date' => '2026-04-15']);
        $this->assertSame('Expired', app(OrderService::class)->statusLabel($quote));

        $this->actingAs($this->owner)->post(route('orders.close', $quote), ['reason' => 'Customer chose another vendor'])->assertRedirect();
        $this->assertSame('Closed', app(OrderService::class)->statusLabel($quote->fresh()));
        $this->actingAs($this->owner)->post(route('orders.reopen', $quote))->assertRedirect();
        $this->assertSame(Order::OPEN, $quote->fresh()->status);
    }

    public function test_permissions_follow_the_sales_module(): void
    {
        $quote = $this->quote();
        $purchases = $this->addMember($this->company, 'purchases');
        $this->actingAs($purchases)->get(route('orders.index', ['kind' => 'quotation']))->assertForbidden();
        $this->actingAs($purchases)->post(route('orders.convert', $quote))->assertForbidden();
        $this->assertSame(0, Order::query()->where('kind', 'sales_order')->count());
    }
}
