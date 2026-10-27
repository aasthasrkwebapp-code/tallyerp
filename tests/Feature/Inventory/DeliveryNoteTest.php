<?php

namespace Tests\Feature\Inventory;

use App\Models\Account;
use App\Models\AccountGroup;
use App\Models\DeliveryNote;
use App\Models\Godown;
use App\Models\Invoice;
use App\Models\Party;
use App\Models\StockItem;
use App\Models\Unit;
use App\Services\Billing\InvoiceService;
use App\Services\Billing\OrderService;
use App\Services\Inventory\BatchTracker;
use App\Services\Inventory\DeliveryNoteService;
use App\Services\Inventory\StockLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class DeliveryNoteTest extends TestCase
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
        // Opening 10 @ 100.
        $this->widget = StockItem::create([
            'name' => 'Widget', 'unit_id' => Unit::where('symbol', 'Nos')->value('id'), 'hsn_sac' => '8471', 'gst_rate' => '18',
            'sales_account_id' => Account::idFor('sales'), 'purchase_account_id' => Account::idFor('purchase'),
            'sales_rate' => '150', 'purchase_rate' => '120',
            'opening_quantity' => '10', 'opening_value' => '1000', 'opening_godown_id' => $this->main->id,
        ]);
    }

    protected function party(string $name, string $group): Account
    {
        $ledger = Account::create(['name' => $name, 'account_group_id' => AccountGroup::idFor($group), 'account_type' => 'party', 'maintain_bill_wise' => true]);
        Party::create(['account_id' => $ledger->id, 'state' => 'Maharashtra', 'gst_registration_type' => 'regular']);

        return $ledger->fresh();
    }

    protected function note(string $kind, string $qty, array $extra = [], ?StockItem $item = null, string $date = '2026-05-02'): DeliveryNote
    {
        return app(DeliveryNoteService::class)->create($kind, [
            'date' => $date,
            'party_account_id' => $kind === 'delivery_note' ? $this->customer->id : $this->supplier->id,
            'lines' => [array_merge(['stock_item_id' => ($item ?? $this->widget)->id, 'godown_id' => $this->main->id, 'quantity' => $qty], $extra)],
        ], $this->owner);
    }

    protected function invoiceFrom(DeliveryNote $note, array $quantities = [], string $intent = 'post'): Invoice
    {
        $input = app(DeliveryNoteService::class)->invoiceInput($note->fresh());
        foreach ($quantities as $n => $q) {
            $input['items'][$n]['quantity'] = $q;
        }
        $input['date'] = '2026-05-05';

        return app(InvoiceService::class)->save(null, $note->config()['invoice_kind'], $input, $this->owner, $intent);
    }

    protected function qty(?string $asOf = null): int
    {
        return app(StockLedger::class)->position($asOf ?? '2026-12-31')[$this->widget->id]->quantity;
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

    public function test_delivery_note_moves_stock_and_the_invoice_does_not_move_it_again(): void
    {
        $dn = $this->note('delivery_note', '4');
        $this->assertSame('DN/0001', $dn->number);
        $this->assertSame('150.00', $dn->items[0]->rate); // selling rate by default
        $this->assertSame(6000, $this->qty());
        $this->assertSame(0, \App\Models\Voucher::query()->count()); // no accounting yet
        $this->assertSame('Not invoiced', app(DeliveryNoteService::class)->statusLabel($dn));

        $invoice = $this->invoiceFrom($dn, [0 => '3']);
        $this->assertSame($dn->items[0]->id, $invoice->items[0]->note_item_id);
        $this->assertSame(6000, $this->qty()); // still 6: the note moved it
        $this->assertSame('Part invoiced', app(DeliveryNoteService::class)->statusLabel($dn->fresh()));
        $this->assertSame('531.00', $invoice->grand_total); // 3 × 150 + 18%

        $this->assertStringContainsString('only 1 is still to be invoiced', $this->errors(fn () => $this->invoiceFrom($dn, [0 => '2']))['items.0.quantity'][0]);
        $this->invoiceFrom($dn, [0 => '1']);
        $this->assertSame('Invoiced', app(DeliveryNoteService::class)->statusLabel($dn->fresh()));
        $this->assertSame(0, app(DeliveryNoteService::class)->pending('delivery_note')->count());

        // The note cannot be cancelled while posted invoices use it.
        $this->assertArrayHasKey('note', $this->errors(fn () => app(DeliveryNoteService::class)->cancel($dn->fresh(), $this->owner, 'x')));
        foreach (Invoice::all() as $inv) {
            app(InvoiceService::class)->cancel($inv, $this->owner, 'Undo');
        }
        app(DeliveryNoteService::class)->cancel($dn->fresh(), $this->owner, 'Goods came back');
        $this->assertSame(10000, $this->qty());
    }

    public function test_goods_receipt_note_brings_stock_in_at_its_rate(): void
    {
        $grn = $this->note('receipt_note', '10', ['rate' => '120']);
        $this->assertSame('GRN/0001', $grn->number);
        $p = app(StockLedger::class)->position('2026-12-31')[$this->widget->id];
        $this->assertSame(20000, $p->quantity);
        $this->assertSame(11000, $p->rate); // (1000 + 1200) / 20

        $bill = $this->invoiceFrom($grn, [], 'post');
        $this->assertSame(20000, $this->qty()); // the bill does not add stock again
        $this->assertSame('120.00', $bill->items[0]->rate);
        $this->assertSame('Billed', app(DeliveryNoteService::class)->statusLabel($grn->fresh()));
    }

    public function test_notes_fulfil_orders_without_double_counting(): void
    {
        $orders = app(OrderService::class);
        $order = $orders->save(null, 'sales_order', [
            'date' => '2026-05-01', 'party_account_id' => $this->customer->id,
            'items' => [['stock_item_id' => $this->widget->id, 'quantity' => '8', 'rate' => '140', 'gst_rate' => '18']],
        ], $this->owner);

        $input = app(DeliveryNoteService::class)->orderInput($order);
        $this->assertSame('8', $input['lines'][0]['quantity']);
        $this->assertSame('140.00', $input['lines'][0]['rate']);

        $dn = $this->note('delivery_note', '5', ['order_item_id' => $order->items[0]->id]);
        $this->assertSame([3000], $orders->progress($order->fresh())->pluck('pending')->all());
        $this->assertStringContainsString('only 3 is pending', $this->errors(fn () => $this->note('delivery_note', '4', ['order_item_id' => $order->items[0]->id]))['lines.0.quantity'][0]);
        $this->assertArrayHasKey('order', $this->errors(fn () => $orders->save($order->fresh(), 'sales_order', $orders->toInput($order), $this->owner)));

        // Invoicing the note takes the order's rate and does not count against the order again.
        $invoice = $this->invoiceFrom($dn);
        $this->assertSame('140.00', $invoice->items[0]->rate);
        $this->assertNull($invoice->items[0]->order_item_id);
        $this->assertSame([3000], $orders->progress($order->fresh())->pluck('pending')->all());
        $this->assertSame('Part delivered', $orders->statusLabel($order->fresh()));

        // The rest invoiced straight from the order.
        $rest = $orders->invoiceInput($order->fresh());
        $this->assertSame('3', $rest['items'][0]['quantity']);
    }

    public function test_serials_and_batches_move_on_the_note(): void
    {
        $phone = StockItem::create(['name' => 'Phone', 'unit_id' => Unit::where('symbol', 'Nos')->value('id'), 'tracking' => 'serial', 'sales_rate' => '12000',
            'sales_account_id' => Account::idFor('sales'), 'purchase_account_id' => Account::idFor('purchase')]);
        $grn = $this->note('receipt_note', '2', ['rate' => '9000', 'serials' => 'P1, P2'], $phone);
        $this->assertArrayHasKey('lines.0.serials', $this->errors(fn () => $this->note('receipt_note', '1', ['serials' => 'P1'], $phone)));

        $dn = $this->note('delivery_note', '1', ['serials' => 'p2'], $phone, '2026-05-03');
        $states = app(BatchTracker::class)->serialStates([$phone->id])[$phone->id];
        $this->assertFalse($states['P2']->in_stock);
        $this->assertSame('delivery', $states['P2']->kind);

        // The invoice for the note carries the serial but does not need it in stock.
        $invoice = $this->invoiceFrom($dn);
        $this->assertSame('P2', $invoice->items[0]->serials);

        // The GRN cannot be cancelled now that P2 has gone out.
        $this->assertStringContainsString('already gone out', $this->errors(fn () => app(DeliveryNoteService::class)->cancel($grn->fresh(), $this->owner, 'x'))['note'][0]);
    }

    public function test_invoice_lines_must_match_their_note(): void
    {
        $dn = $this->note('delivery_note', '2');
        $line = $dn->items[0];
        $save = fn (string $kind, Account $party, array $extra = []) => app(InvoiceService::class)->save(null, $kind, [
            'date' => '2026-05-05', 'party_account_id' => $party->id,
            'items' => [array_merge(['stock_item_id' => $this->widget->id, 'quantity' => '1', 'rate' => '150', 'gst_rate' => '0', 'note_item_id' => $line->id], $extra)],
        ], $this->owner);

        $other = $this->party('Someone Else', 'sundry_debtors');
        $this->assertArrayHasKey('items.0.note_item_id', $this->errors(fn () => $save('sales', $other)));
        $this->assertArrayHasKey('items.0.note_item_id', $this->errors(fn () => $save('credit_note', $this->customer)));
        $this->assertArrayHasKey('items.0.note_item_id', $this->errors(fn () => $save('purchase', $this->supplier)));

        app(DeliveryNoteService::class)->cancel($dn, $this->owner, 'Wrong');
        $this->assertStringContainsString('cancelled', $this->errors(fn () => $save('sales', $this->customer))['items.0.note_item_id'][0]);
    }

    public function test_note_screens_and_reports(): void
    {
        $order = app(OrderService::class)->save(null, 'purchase_order', [
            'date' => '2026-05-01', 'party_account_id' => $this->supplier->id,
            'items' => [['stock_item_id' => $this->widget->id, 'quantity' => '6', 'rate' => '110', 'gst_rate' => '18']],
        ], $this->owner);

        $this->actingAs($this->owner)->get(route('orders.show', $order))->assertOk()->assertSee('Make goods receipt note');
        $this->actingAs($this->owner)->get(route('notes.create', ['kind' => 'receipt_note', 'order' => $order->id]))->assertOk()
            ->assertSee('Order PO/0001 · line 1')->assertSee('value="6"', false);
        $this->actingAs($this->owner)->post(route('notes.store'), [
            'kind' => 'receipt_note', 'date' => '2026-05-03', 'party_account_id' => $this->supplier->id, 'reference' => 'CH-55',
            'lines' => [['stock_item_id' => $this->widget->id, 'godown_id' => $this->main->id, 'quantity' => '6', 'order_item_id' => $order->items[0]->id]],
        ])->assertRedirect();
        $grn = DeliveryNote::query()->firstOrFail();
        $this->assertSame('110.00', $grn->items[0]->rate); // from the order

        $this->actingAs($this->owner)->get(route('notes.index', ['kind' => 'receipt_note']))->assertOk()->assertSee('GRN/0001')->assertSee('Not billed');
        $this->actingAs($this->owner)->get(route('notes.show', $grn))->assertOk()->assertSee('Make purchase bill')->assertSee('CH-55');
        $this->actingAs($this->owner)->get(route('notes.print', $grn))->assertOk()->assertSee('Goods Receipt Note');
        $this->assertStringStartsWith('%PDF', $this->actingAs($this->owner)->get(route('notes.pdf', $grn))->getContent());
        $this->actingAs($this->owner)->get(route('notes.pending', ['kind' => 'receipt_note']))->assertOk()->assertSee('GRN/0001')->assertSee('660.00');
        $csv = $this->actingAs($this->owner)->get(route('notes.pending', ['kind' => 'receipt_note', 'export' => 'csv']))->streamedContent();
        $this->assertStringContainsString('GRN/0001', $csv);

        $this->actingAs($this->owner)->get(route('invoices.create', ['kind' => 'purchase', 'note' => $grn->id]))->assertOk()
            ->assertSee('GRN/0001 · line 1')->assertSee('stock already moved by the note');
        $this->actingAs($this->owner)->get(route('invoices.create', ['kind' => 'sales', 'note' => $grn->id]))->assertNotFound();
        $this->actingAs($this->owner)->get(route('notes.create', ['kind' => 'delivery_note']))->assertOk()->assertSee('New delivery note');
        $this->actingAs($this->owner)->get(route('stock-items.show', $this->widget))->assertOk()->assertSee('Goods receipt note')->assertSee(route('notes.show', $grn), false);

        $clerk = $this->addMember($this->company, 'sales');
        $this->actingAs($clerk)->get(route('notes.index', ['kind' => 'delivery_note']))->assertOk();
        $this->actingAs($clerk)->get(route('notes.show', $grn))->assertForbidden();
    }
}
