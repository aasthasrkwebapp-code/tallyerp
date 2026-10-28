<?php

namespace Tests\Feature\Inventory;

use App\Models\Account;
use App\Models\AccountGroup;
use App\Models\DeliveryNote;
use App\Models\Godown;
use App\Models\GrniProvision;
use App\Models\Party;
use App\Models\StockItem;
use App\Models\Unit;
use App\Models\Voucher;
use App\Services\Billing\InvoiceService;
use App\Services\Inventory\DeliveryNoteService;
use App\Services\Inventory\GrniService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class GrniProvisionTest extends TestCase
{
    use RefreshDatabase;

    protected $owner;
    protected $company;
    protected Account $supplier;
    protected Account $rawPurchases;
    protected StockItem $widget;
    protected StockItem $steel;
    protected Godown $main;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->owner, $this->company] = $this->createCompanyWithOwner();
        $this->main = Godown::query()->where('is_default', true)->firstOrFail();
        $this->supplier = Account::create(['name' => 'Pune Supplies', 'account_group_id' => AccountGroup::idFor('sundry_creditors'), 'account_type' => 'party', 'maintain_bill_wise' => true]);
        Party::create(['account_id' => $this->supplier->id, 'state' => 'Maharashtra', 'gst_registration_type' => 'regular']);
        $this->rawPurchases = Account::create(['name' => 'Raw Material Purchases', 'account_group_id' => AccountGroup::idFor('purchase_accounts'), 'account_type' => 'purchase']);
        $this->widget = $this->item('Widget', Account::idFor('purchase'));
        $this->steel = $this->item('Steel', $this->rawPurchases->id);
    }

    protected function item(string $name, int $purchaseAccount): StockItem
    {
        return StockItem::create([
            'name' => $name, 'unit_id' => Unit::where('symbol', 'Nos')->value('id'), 'gst_rate' => '0',
            'sales_account_id' => Account::idFor('sales'), 'purchase_account_id' => $purchaseAccount,
        ]);
    }

    protected function grn(StockItem $item, string $qty, string $rate, string $date = '2026-05-02'): DeliveryNote
    {
        return app(DeliveryNoteService::class)->create('receipt_note', [
            'date' => $date, 'party_account_id' => $this->supplier->id,
            'lines' => [['stock_item_id' => $item->id, 'godown_id' => $this->main->id, 'quantity' => $qty, 'rate' => $rate]],
        ], $this->owner);
    }

    protected function bill(DeliveryNote $note, string $qty, string $date): void
    {
        $input = app(DeliveryNoteService::class)->invoiceInput($note->fresh());
        $input['items'][0]['quantity'] = $qty;
        $input['date'] = $date;
        app(InvoiceService::class)->save(null, 'purchase', $input, $this->owner, 'post');
    }

    /** @return array<int, string> account id => "Dr 1200.00" */
    protected function entries(Voucher $voucher): array
    {
        return $voucher->entries->mapWithKeys(fn ($e) => [$e->account_id => (float) $e->debit > 0 ? 'Dr '.$e->debit : 'Cr '.$e->credit])->all();
    }

    public function test_preview_follows_the_date(): void
    {
        $widgets = $this->grn($this->widget, '10', '120');
        $this->grn($this->steel, '2', '500', '2026-05-20');
        $this->grn($this->widget, '5', '0', '2026-05-21'); // no rate
        $this->bill($widgets, '4', '2026-06-05');

        $may = app(GrniService::class)->preview('2026-05-31');
        $this->assertSame(220000, $may['total']);
        $this->assertSame([['Purchase', 120000], ['Raw Material Purchases', 100000]], $may['ledgers']->map(fn ($l) => [$l->name, $l->amount])->all());
        $this->assertCount(1, $may['unvalued']);

        // The bill of 4 on 5 June leaves 6 unbilled; the steel GRN is dated after 10 May.
        $this->assertSame(172000, app(GrniService::class)->preview('2026-06-30')['total']);
        $this->assertSame(120000, app(GrniService::class)->preview('2026-05-10')['total']);
        $this->assertSame(0, app(GrniService::class)->preview('2026-05-01')['total']);
    }

    public function test_provision_is_posted_and_reversed(): void
    {
        $this->grn($this->widget, '10', '120');
        $this->grn($this->steel, '2', '500', '2026-05-20');
        $service = app(GrniService::class);

        $provision = $service->post('2026-05-31', true, $this->owner);
        $grni = Account::idFor('grni');
        $this->assertNotNull($grni);
        $this->assertSame('Goods Received Not Invoiced', Account::find($grni)->name);
        $this->assertSame('2200.00', $provision->amount);
        $this->assertSame('2026-05-31', $provision->voucher->date->toDateString());
        $this->assertSame([Account::idFor('purchase') => 'Dr 1200.00', $this->rawPurchases->id => 'Dr 1000.00', $grni => 'Cr 2200.00'], $this->entries($provision->voucher));
        $this->assertSame('2026-06-01', $provision->reversal->date->toDateString());
        $this->assertSame([$grni => 'Dr 2200.00', Account::idFor('purchase') => 'Cr 1200.00', $this->rawPurchases->id => 'Cr 1000.00'], $this->entries($provision->reversal));

        try {
            $service->post('2026-05-31', true, $this->owner);
            $this->fail('Posted twice for one date.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('date', $e->errors());
        }

        $service->cancel($provision, $this->owner, 'Recount');
        $this->assertTrue($provision->voucher->fresh()->isCancelled());
        $this->assertTrue($provision->reversal->fresh()->isCancelled());

        // Without reversal.
        $again = $service->post('2026-05-31', false, $this->owner);
        $this->assertNull($again->reversal_voucher_id);
        $this->assertSame(2, GrniProvision::count());

        $this->expectException(ValidationException::class);
        $service->post('2026-05-01', true, $this->owner); // nothing unbilled then
    }

    public function test_screen_and_permissions(): void
    {
        $this->grn($this->widget, '10', '120');
        $this->actingAs($this->owner)->get(route('grni.index', ['date' => '2026-05-31']))
            ->assertOk()->assertSee('Goods received not invoiced')->assertSee('GRN/0001')->assertSee('Post provision');

        $this->post(route('grni.store'), ['date' => '2026-05-31', 'reverse' => '1'])->assertRedirect(route('grni.index', ['date' => '2026-05-31']));
        $provision = GrniProvision::firstOrFail();
        $this->get(route('grni.index', ['date' => '2026-05-31']))->assertSee('Posted as')->assertDontSee('Post provision');
        $this->get(route('vouchers.show', $provision->voucher_id))->assertSee('goods received not invoiced provision');

        $viewer = $this->addMember($this->company, 'viewer');
        $this->actingAs($viewer)->get(route('grni.index'))->assertOk();
        $this->actingAs($viewer)->post(route('grni.store'), ['date' => '2026-05-31'])->assertForbidden();
        $this->actingAs($viewer)->post(route('grni.cancel', $provision), ['reason' => 'x'])->assertForbidden();

        $this->actingAs($this->owner)->post(route('grni.cancel', $provision), ['reason' => 'Wrong'])->assertRedirect();
        $this->assertSame('cancelled', $provision->fresh()->status);
    }
}
