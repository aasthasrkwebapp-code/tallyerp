<?php

namespace Tests\Feature\Gst;

use App\Models\Account;
use App\Models\AccountGroup;
use App\Models\EInvoice;
use App\Models\Invoice;
use App\Models\Party;
use App\Models\StockItem;
use App\Models\Unit;
use App\Services\Billing\InvoiceService;
use App\Services\Gst\EInvoiceJson;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class EInvoiceTest extends TestCase
{
    use RefreshDatabase;

    protected $owner;
    protected $company;
    protected Account $buyer;
    protected Account $consumer;
    protected StockItem $rod;

    protected const IRN = 'a5c12dca80e743321740b001fd70953e8738d109865d28ba4013750f2046f229';

    protected function setUp(): void
    {
        parent::setUp();
        [$this->owner, $this->company] = $this->createCompanyWithOwner(['state' => 'Maharashtra', 'gstin' => '27AAACA1234A1Z5', 'legal_name' => 'Asha Steel Pvt Ltd',
            'address' => "Plot 4, MIDC\nBhosari", 'city' => 'Pune', 'pincode' => '411026', 'phone' => '020-2712 3456']);
        $this->buyer = $this->party('Pune Builders', 'Maharashtra', '27BBBBB1234B1Z5');
        $this->consumer = $this->party('Walk-in', 'Maharashtra', null);
        $this->rod = StockItem::create(['name' => 'Steel Rod 12mm', 'unit_id' => Unit::where('symbol', 'Nos')->value('id'), 'hsn_sac' => '7214', 'gst_rate' => '18',
            'opening_quantity' => '1000', 'opening_value' => '100000', 'sales_account_id' => Account::idFor('sales')]);
    }

    protected function party(string $name, string $state, ?string $gstin): Account
    {
        $a = Account::create(['name' => $name, 'account_group_id' => AccountGroup::idFor('sundry_debtors'), 'account_type' => 'party']);
        Party::create(['account_id' => $a->id, 'state' => $state, 'gst_registration_type' => $gstin ? 'regular' : 'consumer', 'gstin' => $gstin,
            'pincode' => '411001', 'city' => 'Pune', 'billing_address' => 'FC Road']);

        return $a;
    }

    protected function sale(Account $party, string $kind = 'sales', string $qty = '10'): Invoice
    {
        return app(InvoiceService::class)->save(null, $kind, ['date' => '2026-10-10', 'party_account_id' => $party->id,
            'items' => [['stock_item_id' => $this->rod->id, 'quantity' => $qty, 'rate' => '500', 'discount_percent' => '10']]], $this->owner);
    }

    public function test_document_json_and_which_invoices_apply(): void
    {
        $sale = $this->sale($this->buyer);
        $service = app(EInvoiceJson::class);
        $this->assertTrue($service->applies($sale));
        $this->assertFalse($service->applies($this->sale($this->consumer)));
        $note = $this->sale($this->buyer, 'credit_note', '2');
        $this->assertTrue($service->applies($note));

        $doc = $service->document($sale->fresh(['items', 'party.partyDetails']));
        $this->assertSame(['TaxSch' => 'GST', 'SupTyp' => 'B2B', 'RegRev' => 'N', 'IgstOnIntra' => 'N'], $doc['TranDtls']);
        $this->assertSame(['Typ' => 'INV', 'No' => $sale->displayNumber(), 'Dt' => '10/10/2026'], $doc['DocDtls']);
        $this->assertSame(['27AAACA1234A1Z5', 'Asha Steel Pvt Ltd', 'Plot 4, MIDC', 'Bhosari', 411026, '27', '02027123456'],
            [$doc['SellerDtls']['Gstin'], $doc['SellerDtls']['LglNm'], $doc['SellerDtls']['Addr1'], $doc['SellerDtls']['Addr2'], $doc['SellerDtls']['Pin'], $doc['SellerDtls']['Stcd'], $doc['SellerDtls']['Ph']]);
        $this->assertSame(['27BBBBB1234B1Z5', '27', 'FC Road', 411001], [$doc['BuyerDtls']['Gstin'], $doc['BuyerDtls']['Pos'], $doc['BuyerDtls']['Addr1'], $doc['BuyerDtls']['Pin']]);
        $this->assertArrayNotHasKey('Addr2', $doc['BuyerDtls']);
        $item = $doc['ItemList'][0];
        $this->assertSame(['1', 'N', '7214', 10.0, 'NOS', 500.0, 5000.0, 500.0, 4500.0, 18.0, 0.0, 405.0, 405.0, 5310.0],
            [$item['SlNo'], $item['IsServc'], $item['HsnCd'], $item['Qty'], $item['Unit'], $item['UnitPrice'], $item['TotAmt'], $item['Discount'], $item['AssAmt'], $item['GstRt'], $item['IgstAmt'], $item['CgstAmt'], $item['SgstAmt'], $item['TotItemVal']]);
        $this->assertSame([4500.0, 405.0, 405.0, 0.0, 5310.0], [$doc['ValDtls']['AssVal'], $doc['ValDtls']['CgstVal'], $doc['ValDtls']['SgstVal'], $doc['ValDtls']['IgstVal'], $doc['ValDtls']['TotInvVal']]);

        $this->assertSame('CRN', $service->document($note->fresh(['items', 'party.partyDetails']))['DocDtls']['Typ']);
        $bulk = json_decode($service->json(Invoice::with('items', 'party.partyDetails')->whereIn('id', [$sale->id, $note->id])->get()), true);
        $this->assertCount(2, $bulk);
        $this->assertSame(EInvoiceJson::VERSION, $bulk[0]['Version']);
    }

    public function test_register_irn_print_and_refusals(): void
    {
        $sale = $this->sale($this->buyer);
        $service = app(EInvoiceJson::class);
        foreach ([['irn' => 'xyz'], ['irn' => strtoupper(self::IRN)]] as $i => $data) {
            try {
                $service->register($i ? $this->sale($this->consumer) : $sale, $data + ['ack_no' => '112010036563310', 'ack_date' => '2026-10-10 11:05']);
                $this->fail('Registered');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('irn', $e->errors());
            }
        }
        $e = $service->register($sale, ['irn' => strtoupper(self::IRN), 'ack_no' => '112010036563310', 'ack_date' => '2026-10-10 11:05', 'signed_qr' => 'eyJhbGciOiJSUzI1NiJ9.test.sig']);
        $this->assertSame(self::IRN, $e->irn);
        $other = $this->sale($this->buyer, 'sales', '3');
        $this->expectExceptionObject(ValidationException::withMessages(['irn' => 'This IRN is already on another invoice.']));
        $service->register($other, ['irn' => self::IRN, 'ack_no' => '1', 'ack_date' => '2026-10-10']);
    }

    public function test_screens_print_pdf_and_permissions(): void
    {
        $sale = $this->sale($this->buyer);
        $this->actingAs($this->owner)->get(route('invoices.show', $sale))->assertOk()->assertSee('E-invoice')->assertSee('No IRN yet')->assertSee('JSON for the IRP');
        $this->get(route('gst.e-invoices.index', ['month' => '2026-10']))->assertOk()->assertSee($sale->displayNumber())->assertSee('Pending');
        $json = $this->get(route('gst.e-invoices.json', ['ids' => [$sale->id]]));
        $json->assertOk();
        $this->assertSame('B2B', json_decode($json->getContent(), true)[0]['TranDtls']['SupTyp']);

        $this->put(route('gst.e-invoices.update', $sale), ['irn' => self::IRN, 'ack_no' => 'abc', 'ack_date' => '2026-10-10T11:05'])->assertSessionHasErrors('ack_no');
        $this->put(route('gst.e-invoices.update', $sale), ['irn' => self::IRN, 'ack_no' => '112010036563310', 'ack_date' => '2026-10-10T11:05', 'signed_qr' => 'eyJhbGciOiJSUzI1NiJ9.test.sig'])
            ->assertRedirect(route('invoices.show', $sale));
        $this->get(route('invoices.show', $sale))->assertSee('IRN registered')->assertSee(self::IRN)->assertDontSee('JSON for the IRP');
        $this->get(route('invoices.print', $sale))->assertOk()->assertSee('IRN: <strong>'.self::IRN, false)->assertSee('<svg', false);
        $pdf = $this->get(route('invoices.pdf', $sale));
        $pdf->assertOk();
        $this->get(route('gst.e-invoices.index', ['month' => '2026-10']))->assertDontSee('Download JSON for ticked');

        $viewer = $this->addMember($this->company, 'viewer');
        $this->actingAs($viewer)->put(route('gst.e-invoices.update', $sale), ['irn' => self::IRN, 'ack_no' => '1', 'ack_date' => '2026-10-10'])->assertForbidden();
        $this->actingAs($viewer)->post(route('gst.e-invoices.cancel', $sale))->assertForbidden();
        $this->actingAs($this->owner)->post(route('gst.e-invoices.cancel', $sale))->assertRedirect();
        $this->assertNotNull(EInvoice::first()->cancelled_at);
        $this->get(route('invoices.print', $sale))->assertDontSee('IRN:');
        $this->get(route('invoices.show', $sale))->assertSee('IRN cancelled')->assertSee('Save IRN');
    }
}
