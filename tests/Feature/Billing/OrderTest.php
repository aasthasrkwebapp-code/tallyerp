<?php

namespace Tests\Feature\Billing;

use App\Models\Account;
use App\Models\AccountGroup;
use App\Models\AuditLog;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Party;
use App\Models\StockItem;
use App\Models\Unit;
use App\Services\Billing\InvoiceService;
use App\Services\Billing\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class OrderTest extends TestCase
{
    use RefreshDatabase;

    protected $owner;
    protected $company;
    protected Account $customer;
    protected Account $supplier;
    protected StockItem $widget;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->owner, $this->company] = $this->createCompanyWithOwner();
        $this->customer = $this->party('Mumbai Retail', 'sundry_debtors', 'Maharashtra');
        $this->supplier = $this->party('Delhi Supplies', 'sundry_creditors', 'Delhi');
        $this->widget = StockItem::create([
            'name' => 'Widget', 'unit_id' => Unit::where('symbol', 'Nos')->value('id'), 'hsn_sac' => '8471', 'gst_rate' => '18',
            'sales_account_id' => Account::idFor('sales'), 'purchase_account_id' => Account::idFor('purchase'), 'sales_rate' => '150',
        ]);
    }

    protected function party(string $name, string $group, string $state): Account
    {
        $ledger = Account::create(['name' => $name, 'account_group_id' => AccountGroup::idFor($group), 'account_type' => 'party', 'maintain_bill_wise' => true]);
        Party::create(['account_id' => $ledger->id, 'state' => $state, 'gst_registration_type' => 'regular']);

        return $ledger->fresh();
    }

    protected function order(string $kind = 'sales_order', array $items = null, array $extra = []): Order
    {
        return app(OrderService::class)->save(null, $kind, array_merge([
            'date' => '2026-05-01',
            'delivery_date' => '2026-05-15',
            'party_account_id' => $kind === 'sales_order' ? $this->customer->id : $this->supplier->id,
            'reference' => 'CUST-PO-7',
            'items' => $items ?? [
                ['stock_item_id' => $this->widget->id, 'quantity' => '10', 'rate' => '150', 'gst_rate' => '18'],
                ['description' => 'Installation', 'quantity' => '1', 'rate' => '500', 'gst_rate' => '18', 'hsn_sac' => '998719'],
            ],
        ], $extra), $this->owner);
    }

    /** An invoice from the order's pending lines, quantities optionally changed. */
    protected function invoiceFrom(Order $order, array $quantities = [], string $date = '2026-05-05', string $intent = 'post'): Invoice
    {
        $input = app(OrderService::class)->invoiceInput($order);
        foreach ($quantities as $n => $qty) {
            $input['items'][$n]['quantity'] = $qty;
        }
        foreach ($input['items'] as $n => $row) {
            $input['items'][$n]['account_id'] ??= Account::idFor($order->config()['invoice_kind'] === 'sales' ? 'sales' : 'purchase');
        }
        $input['date'] = $date;

        return app(InvoiceService::class)->save(null, $order->config()['invoice_kind'], $input, $this->owner, $intent);
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

    public function test_orders_are_numbered_and_taxed_without_touching_the_books(): void
    {
        $so = $this->order();
        $this->assertSame('SO/0001', $so->number);
        $this->assertSame('intra', $so->tax_mode);
        $this->assertSame('2000.00', $so->taxable_total);       // 10 × 150 + 500
        $this->assertSame('180.00', $so->cgst_total);
        $this->assertSame('2360.00', $so->grand_total);
        $this->assertSame('Widget', $so->items[0]->description);
        $this->assertSame('8471', $so->items[0]->hsn_sac);

        $po = $this->order('purchase_order');
        $this->assertSame('PO/0001', $po->number);
        $this->assertSame('inter', $po->tax_mode);               // supplier in Delhi
        $this->assertSame('SO/0002', $this->order()->number);

        $this->assertSame(0, \App\Models\Voucher::query()->count());
        $this->assertSame('Pending', app(OrderService::class)->statusLabel($so));
    }

    public function test_order_validation(): void
    {
        $this->assertArrayHasKey('party_account_id', $this->errors(fn () => $this->order('sales_order', null, ['party_account_id' => $this->supplier->id])));
        $this->assertArrayHasKey('items', $this->errors(fn () => $this->order('sales_order', [])));
        $this->assertArrayHasKey('items.0.quantity', $this->errors(fn () => $this->order('sales_order', [['stock_item_id' => $this->widget->id, 'quantity' => '0', 'rate' => '1']])));
        $this->assertArrayHasKey('items.0.description', $this->errors(fn () => $this->order('sales_order', [['quantity' => '1', 'rate' => '1']])));
        $this->assertArrayHasKey('date', $this->errors(fn () => $this->order('sales_order', null, ['date' => '2019-01-01'])));
    }

    public function test_invoices_deliver_orders_in_parts_up_to_the_pending_quantity(): void
    {
        $order = $this->order();
        $service = app(OrderService::class);

        $input = $service->invoiceInput($order);
        $this->assertSame(['10', '1'], array_column($input['items'], 'quantity'));
        $this->assertSame('CUST-PO-7', $input['reference']);
        $this->assertSame(Account::idFor('sales'), $input['items'][0]['account_id']); // from the stock item

        $first = $this->invoiceFrom($order, [0 => '4']);
        $this->assertSame($order->items[0]->id, $first->items[0]->order_item_id);
        $progress = $service->progress($order->fresh());
        $this->assertSame([4000, 1000], $progress->pluck('delivered')->all());
        $this->assertSame([6000, 0], $progress->pluck('pending')->all());
        $this->assertSame('Part delivered', $service->statusLabel($order->fresh()));

        // The next invoice offers only what is pending, and cannot take more.
        $this->assertSame(['6'], array_column($service->invoiceInput($order->fresh())['items'], 'quantity'));
        $this->assertStringContainsString('only 6 is pending', $this->errors(fn () => $this->invoiceFrom($order->fresh(), [0 => '7']))['items.0.quantity'][0]);

        // Drafts do not count as delivered.
        $this->invoiceFrom($order->fresh(), [0 => '6'], '2026-05-06', 'draft');
        $this->assertSame(6000, $service->progress($order->fresh())[0]->pending);

        $this->invoiceFrom($order->fresh(), [0 => '6'], '2026-05-07');
        $this->assertSame('Delivered', $service->statusLabel($order->fresh()));
        $this->assertArrayHasKey('order', $this->errors(fn () => $service->invoiceInput($order->fresh())));

        // Cancelling an invoice puts its quantity back on the order.
        app(InvoiceService::class)->cancel($first, $this->owner, 'Wrong rate');
        $this->assertSame([4000, 1000], $service->progress($order->fresh())->pluck('pending')->all());

        // Editing a posted invoice does not count its own quantity twice.
        $second = Invoice::query()->whereHas('voucher', fn ($q) => $q->where('status', 'posted'))->latest('id')->first();
        $input = app(InvoiceService::class)->toInput($second);
        $input['items'][0]['quantity'] = '10';
        app(InvoiceService::class)->save($second, 'sales', $input, $this->owner, 'post', 'Full quantity');
        $this->assertSame(0, $service->progress($order->fresh())[0]->pending);
    }

    public function test_invoice_lines_must_match_their_order(): void
    {
        $order = $this->order();
        $other = $this->party('Other Customer', 'sundry_debtors', 'Maharashtra');
        $line = $order->items[0];
        $save = fn (array $item, ?Account $party = null, string $kind = 'sales') => app(InvoiceService::class)->save(null, $kind, [
            'date' => '2026-05-05', 'party_account_id' => ($party ?? $this->customer)->id,
            'items' => [array_merge(['stock_item_id' => $this->widget->id, 'quantity' => '1', 'rate' => '150', 'gst_rate' => '18', 'order_item_id' => $line->id], $item)],
        ], $this->owner);

        $this->assertArrayHasKey('items.0.order_item_id', $this->errors(fn () => $save([], $other)));
        $this->assertArrayHasKey('items.0.order_item_id', $this->errors(fn () => $save(['stock_item_id' => null, 'account_id' => Account::idFor('sales'), 'description' => 'X'])));
        $this->assertArrayHasKey('items.0.order_item_id', $this->errors(fn () => $save([], $this->supplier, 'purchase')));

        app(OrderService::class)->close($order, $this->owner, 'Customer dropped it');
        $this->assertStringContainsString('closed', $this->errors(fn () => $save([]))['items.0.order_item_id'][0]);
    }

    public function test_closing_cancelling_and_altering_orders(): void
    {
        $service = app(OrderService::class);
        $order = $this->order();

        // Alter while nothing is invoiced.
        $input = $service->toInput($order);
        $input['items'][0]['quantity'] = '12';
        $order = $service->save($order, 'sales_order', $input, $this->owner);
        $this->assertSame('12.000', $order->items[0]->quantity);

        $this->invoiceFrom($order, [0 => '2'], '2026-05-05', 'draft');
        $this->assertArrayHasKey('order', $this->errors(fn () => $service->save($order->fresh(), 'sales_order', $input, $this->owner)));

        Invoice::query()->first()->items()->delete();
        Invoice::query()->delete();
        $this->invoiceFrom($order->fresh(), [0 => '2']);
        $this->assertArrayHasKey('order', $this->errors(fn () => $service->cancel($order->fresh(), $this->owner, 'x')));

        $service->close($order->fresh(), $this->owner, 'Balance not needed');
        $this->assertSame('Closed', $service->statusLabel($order->fresh()));
        $this->assertSame(0, $service->pending('sales_order')->count());
        $this->assertTrue(AuditLog::query()->where('event', 'closed')->exists());

        $service->reopen($order->fresh(), $this->owner);
        $this->assertSame([10000], $service->pending('sales_order')->pluck('pending')->all()); // widget 12 − 2; installation was invoiced

        $fresh = $this->order();
        $service->cancel($fresh, $this->owner, 'Duplicate');
        $this->assertSame('Cancelled', $service->statusLabel($fresh->fresh()));
        $this->assertArrayHasKey('order', $this->errors(fn () => $service->invoiceInput($fresh->fresh())));
    }

    public function test_order_screens_and_pending_report(): void
    {
        $order = $this->order();
        $this->invoiceFrom($order, [0 => '3']);

        $this->actingAs($this->owner)->get(route('orders.index', ['kind' => 'sales_order']))->assertOk()->assertSee('SO/0001')->assertSee('Part delivered');
        $this->actingAs($this->owner)->get(route('orders.create', ['kind' => 'purchase_order']))->assertOk()->assertSee('New purchase order');
        $this->actingAs($this->owner)->get(route('orders.show', $order))->assertOk()->assertSee('Make sales invoice')->assertSee('7 Nos');
        $this->actingAs($this->owner)->get(route('orders.edit', $order))->assertOk();
        $this->actingAs($this->owner)->get(route('invoices.create', ['kind' => 'sales', 'order' => $order->id]))->assertOk()
            ->assertSee('Order SO/0001 · line 1')->assertSee('name="items[0][order_item_id]"', false);
        $this->actingAs($this->owner)->get(route('invoices.create', ['kind' => 'purchase', 'order' => $order->id]))->assertNotFound();
        $this->actingAs($this->owner)->get(route('orders.print', $order))->assertOk()->assertSee('Sales Order');
        $pdf = $this->actingAs($this->owner)->get(route('orders.pdf', $order));
        $pdf->assertOk();
        $this->assertStringStartsWith('%PDF', $pdf->getContent());

        $this->actingAs($this->owner)->get(route('orders.pending', ['kind' => 'sales_order']))->assertOk()->assertSee('SO/0001')->assertSee('7 Nos');
        $csv = $this->actingAs($this->owner)->get(route('orders.pending', ['kind' => 'sales_order', 'export' => 'csv']))->streamedContent();
        $this->assertStringContainsString('SO/0001', $csv);

        $this->actingAs($this->owner)->post(route('orders.store'), [
            'kind' => 'purchase_order', 'date' => '2026-05-02', 'party_account_id' => $this->supplier->id,
            'items' => [['stock_item_id' => $this->widget->id, 'quantity' => '5', 'rate' => '100', 'gst_rate' => '18']],
        ])->assertRedirect();
        $this->assertSame(1, Order::query()->ofKind('purchase_order')->count());
        $this->actingAs($this->owner)->post(route('orders.close', $order), ['reason' => 'Done'])->assertRedirect(route('orders.show', $order));
        $this->assertSame('closed', $order->fresh()->status);
    }

    public function test_order_permissions_follow_sales_and_purchases(): void
    {
        $order = $this->order();
        $clerk = $this->addMember($this->company, 'purchases');

        $this->actingAs($clerk)->get(route('orders.index', ['kind' => 'purchase_order']))->assertOk();
        $this->actingAs($clerk)->get(route('orders.index', ['kind' => 'sales_order']))->assertForbidden();
        $this->actingAs($clerk)->get(route('orders.show', $order))->assertForbidden();
    }
    public function test_advances_are_received_and_adjusted_against_invoices(): void
    {
        $service = app(\App\Services\Billing\OrderAdvanceService::class);
        $bank = Account::create(['name' => 'HDFC Bank', 'account_group_id' => AccountGroup::idFor('bank_accounts'), 'account_type' => 'bank']);
        $so = $this->order(); // 2,360.00
        $entries = fn ($v) => $v->entries->map(fn ($e) => [$e->account_id, (float) $e->debit > 0 ? 'Dr '.$e->debit : 'Cr '.$e->credit, $e->billAllocations->map(fn ($b) => $b->bill_type.' '.$b->bill_ref)->all()])->all();

        $advance = $service->record($so, ['date' => '2026-05-02', 'amount' => '1000', 'account_id' => $bank->id], $this->owner);
        $this->assertSame('ADV-SO/0001-1', $advance->bill_ref);
        $this->assertSame('receipt', $advance->voucher->type->base_type);
        $this->assertSame([[$bank->id, 'Dr 1000.00', []], [$this->customer->id, 'Cr 1000.00', ['advance ADV-SO/0001-1']]], $entries($advance->voucher->fresh('entries.billAllocations')));
        $this->assertSame([$advance->id => 100000], $service->pending($so->fresh('advances')));
        $this->assertArrayHasKey('amount', $this->errors(fn () => $service->record($so, ['date' => '2026-05-02', 'amount' => '1400', 'account_id' => $bank->id], $this->owner)));
        $this->assertArrayHasKey('account_id', $this->errors(fn () => $service->record($so, ['date' => '2026-05-02', 'amount' => '10', 'account_id' => Account::idFor('sales')], $this->owner)));

        $invoice = $this->invoiceFrom($so);
        $this->assertSame(236000, $service->invoicesWithPending($so)->first()->pending);

        $adj = $service->adjust($advance, ['invoice_id' => $invoice->id, 'amount' => '1000', 'date' => '2026-05-05'], $this->owner);
        $this->assertSame('journal', $adj->voucher->type->base_type);
        $this->assertSame([0, 136000], [$service->pending($so->fresh('advances'))[$advance->id], $service->invoicesWithPending($so)->first()->pending]);
        $this->assertArrayHasKey('adjust', $this->errors(fn () => $service->adjust($advance, ['invoice_id' => $invoice->id, 'amount' => '1', 'date' => '2026-05-05'], $this->owner)));

        // The advance cannot go while its adjustment stands.
        $this->assertArrayHasKey('advance', $this->errors(fn () => $service->cancel($advance, $this->owner, 'x')));
        $service->cancelAdjustment($adj, $this->owner, 'Wrong invoice');
        $this->assertSame(100000, $service->pending($so->fresh('advances'))[$advance->id]);
        $service->cancel($advance->fresh(), $this->owner, 'Refunded');
        $this->assertTrue($advance->voucher->fresh()->isCancelled());

        // Purchase order: a payment, Dr the supplier.
        $po = $this->order('purchase_order');
        $paid = $service->record($po, ['date' => '2026-05-02', 'amount' => '500', 'account_id' => $bank->id], $this->owner);
        $this->assertSame('payment', $paid->voucher->type->base_type);
        $this->assertSame([[$this->supplier->id, 'Dr 500.00', ['advance ADV-PO/0001-1']], [$bank->id, 'Cr 500.00', []]], $entries($paid->voucher->fresh('entries.billAllocations')));

        // Quotations take no advances.
        $quote = app(OrderService::class)->save(null, 'quotation', ['date' => '2026-05-01', 'party_account_id' => $this->customer->id, 'items' => [['description' => 'X', 'quantity' => '1', 'rate' => '10', 'gst_rate' => '0']]], $this->owner);
        $this->assertArrayHasKey('amount', $this->errors(fn () => $service->record($quote, ['date' => '2026-05-02', 'amount' => '5', 'account_id' => $bank->id], $this->owner)));
    }

    public function test_advance_screens_and_permissions(): void
    {
        $bank = Account::create(['name' => 'HDFC Bank', 'account_group_id' => AccountGroup::idFor('bank_accounts'), 'account_type' => 'bank']);
        $so = $this->order();
        $this->actingAs($this->owner)->post(route('orders.advances.store', $so), ['date' => '2026-05-02', 'amount' => '1000', 'account_id' => $bank->id])->assertRedirect(route('orders.show', $so));
        $advance = \App\Models\OrderAdvance::firstOrFail();
        $invoice = $this->invoiceFrom($so);
        $this->get(route('orders.show', $so))->assertOk()->assertSee('Advances received')->assertSee('ADV-SO/0001-1')->assertSee('Adjust');
        $this->post(route('order-advances.adjust', $advance), ['invoice_id' => $invoice->id, 'amount' => '600', 'date' => '2026-05-05'])->assertRedirect();
        $adj = \App\Models\OrderAdvanceAdjustment::firstOrFail();
        $this->get(route('vouchers.show', $adj->voucher_id))->assertSee('Adjusts advance ADV-SO/0001-1');
        $this->get(route('vouchers.show', $advance->voucher_id))->assertSee('Advance ADV-SO/0001-1 against');

        $viewer = $this->addMember($this->company, 'viewer');
        $this->actingAs($viewer)->get(route('orders.show', $so))->assertOk()->assertDontSee('Record an advance');
        $this->actingAs($viewer)->post(route('orders.advances.store', $so), ['date' => '2026-05-02', 'amount' => '10', 'account_id' => $bank->id])->assertForbidden();
        $this->actingAs($viewer)->post(route('advance-adjustments.cancel', $adj), ['reason' => 'x'])->assertForbidden();
    }
}
