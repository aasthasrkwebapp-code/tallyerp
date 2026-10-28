<?php

namespace Tests\Feature\Gst;

use App\Models\Account;
use App\Models\AccountGroup;
use App\Models\EwayBill;
use App\Models\Invoice;
use App\Models\Party;
use App\Models\StockItem;
use App\Models\Unit;
use App\Services\Billing\InvoiceService;
use App\Services\Gst\EwayBillJson;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class EwayBillTest extends TestCase
{
    use RefreshDatabase;

    protected $owner;
    protected $company;
    protected Account $gujarat;
    protected Account $pune;
    protected StockItem $rod;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->owner, $this->company] = $this->createCompanyWithOwner(['state' => 'Maharashtra', 'gstin' => '27AAACA1234A1Z5', 'legal_name' => 'Asha Steel Pvt Ltd',
            'address' => "Plot 4, MIDC\nBhosari", 'city' => 'Pune', 'pincode' => '411026']);
        $this->gujarat = $this->party('Surat Traders', 'Gujarat', '24ABCDE1234F1Z9', '395003', 'Surat');
        $this->pune = $this->party('Pune Retail', 'Maharashtra', null, '411001', 'Pune');
        $this->rod = StockItem::create(['name' => 'Steel Rod 12mm', 'unit_id' => Unit::where('symbol', 'Nos')->value('id'), 'hsn_sac' => '7214', 'gst_rate' => '18',
            'opening_quantity' => '1000', 'opening_value' => '100000', 'sales_account_id' => Account::idFor('sales')]);
    }

    protected function party(string $name, string $state, ?string $gstin, string $pin, string $city): Account
    {
        $a = Account::create(['name' => $name, 'account_group_id' => AccountGroup::idFor('sundry_debtors'), 'account_type' => 'party']);
        Party::create(['account_id' => $a->id, 'state' => $state, 'gst_registration_type' => $gstin ? 'regular' : 'unregistered', 'gstin' => $gstin,
            'pincode' => $pin, 'city' => $city, 'billing_address' => "Ring Road\nNear Station"]);

        return $a;
    }

    protected function sale(Account $party, string $qty, string $rate = '500', array $extra = []): Invoice
    {
        return app(InvoiceService::class)->save(null, 'sales', [
            'date' => '2026-10-10', 'party_account_id' => $party->id,
            'items' => [['stock_item_id' => $this->rod->id, 'quantity' => $qty, 'rate' => $rate], ...$extra],
        ], $this->owner);
    }

    public function test_json_for_inter_and_intra_state_invoices(): void
    {
        $inter = $this->sale($this->gujarat, '100', '500', [['account_id' => Account::idFor('sales'), 'description' => 'Loading', 'hsn_sac' => '996511', 'quantity' => '1', 'rate' => '1000', 'gst_rate' => '18']]);
        $eway = app(EwayBillJson::class);
        $eway->save($inter, ['trans_mode' => 'road', 'distance_km' => 380, 'vehicle_no' => 'mh-12 ab 1234', 'transporter_name' => 'Fast Roadways', 'trans_doc_no' => 'LR/88', 'trans_doc_date' => '2026-10-10']);
        $this->assertSame('MH12AB1234', $inter->ewayBill()->first()->vehicle_no);

        $bill = $eway->bill($inter->fresh(['ewayBill', 'items', 'party.partyDetails']));
        $this->assertSame(['O', 1, 'INV', '10/10/2026', '27AAACA1234A1Z5', 'Asha Steel Pvt Ltd', 'Plot 4, MIDC', 'Bhosari', 411026, 27],
            [$bill['supplyType'], $bill['subSupplyType'], $bill['docType'], $bill['docDate'], $bill['fromGstin'], $bill['fromTrdName'], $bill['fromAddr1'], $bill['fromAddr2'], $bill['fromPincode'], $bill['fromStateCode']]);
        $this->assertSame(['24ABCDE1234F1Z9', 395003, 24, 'Ring Road'], [$bill['toGstin'], $bill['toPincode'], $bill['toStateCode'], $bill['toAddr1']]);
        $this->assertSame([51000.0, 0.0, 9180.0, 60180.0], [$bill['totalValue'], $bill['cgstValue'], $bill['igstValue'], $bill['totInvValue']]);
        $this->assertSame([1, '380', 'MH12AB1234', 'R', 'LR/88', '10/10/2026', 7214], [$bill['transMode'], $bill['transDistance'], $bill['vehicleNo'], $bill['vehicleType'], $bill['transDocNo'], $bill['transDocDate'], $bill['mainHsnCode']]);
        $this->assertSame(['hsnCode' => 7214, 'quantity' => 100.0, 'qtyUnit' => 'NOS', 'taxableAmount' => 50000.0, 'cgstRate' => 0, 'igstRate' => 18.0],
            array_intersect_key($bill['itemList'][0], array_flip(['hsnCode', 'quantity', 'qtyUnit', 'taxableAmount', 'igstRate', 'cgstRate'])));
        $this->assertTrue($eway->needed($inter));

        $intra = $this->sale($this->pune, '120');
        $eway->save($intra, ['trans_mode' => 'road', 'distance_km' => 20, 'transporter_id' => '27AAACT1234A1Z1']);
        $json = json_decode($eway->json(Invoice::with('ewayBill', 'items', 'party.partyDetails')->whereIn('id', [$inter->id, $intra->id])->get()), true);
        $this->assertSame(EwayBillJson::VERSION, $json['version']);
        $this->assertCount(2, $json['billLists']);
        $b = $json['billLists'][1];
        $this->assertSame(['URP', 27, 5400, 5400, '27AAACT1234A1Z1', ''], [$b['toGstin'], $b['toStateCode'], $b['cgstValue'], $b['sgstValue'], $b['transporterId'], $b['vehicleNo']]);
        $this->assertSame([9, 9], [$b['itemList'][0]['cgstRate'], $b['itemList'][0]['sgstRate']]);

        // Small invoices and services do not need one.
        $this->assertFalse($eway->needed($this->sale($this->pune, '10')));
    }

    public function test_refusals_and_problems(): void
    {
        $sale = $this->sale($this->gujarat, '100');
        $eway = app(EwayBillJson::class);
        $refused = function (array $data, string $field) use ($eway, $sale) {
            try {
                $eway->save($sale, $data + ['trans_mode' => 'road', 'distance_km' => 100]);
                $this->fail('Saved');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey($field, $e->errors());
            }
        };
        $refused([], 'vehicle_no');
        $refused(['vehicle_no' => 'ABC'], 'vehicle_no');
        $refused(['transporter_id' => '123'], 'transporter_id');
        $refused(['trans_mode' => 'rail'], 'trans_doc_no');
        $refused(['vehicle_no' => 'MH12AB1234', 'ewb_no' => '1234'], 'ewb_no');

        $this->assertSame([], $eway->problems($sale));
        $this->gujarat->partyDetails()->update(['pincode' => null]);
        $this->rod->update(['hsn_sac' => null]);
        $plain = app(InvoiceService::class)->save(null, 'sales', ['date' => '2026-10-10', 'party_account_id' => $this->gujarat->id,
            'items' => [['stock_item_id' => $this->rod->id, 'quantity' => '1', 'rate' => '100']]], $this->owner);
        $this->assertSame(['Surat Traders has no valid PIN code.', 'Line 1 (Steel Rod 12mm) has no HSN code.'], $eway->problems($plain->fresh(['items', 'party.partyDetails'])));
        $eway->save($plain, ['trans_mode' => 'road', 'distance_km' => 100, 'vehicle_no' => 'MH12AB1234']);
        try {
            $eway->json(Invoice::with('ewayBill', 'items', 'party.partyDetails')->whereKey($plain->id)->get());
            $this->fail('Made JSON');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('no valid PIN code', $e->errors()['invoices'][0]);
        }
        $draft = app(InvoiceService::class)->save(null, 'sales', ['date' => '2026-10-10', 'party_account_id' => $this->pune->id,
            'items' => [['stock_item_id' => $this->rod->id, 'quantity' => '1', 'rate' => '100']]], $this->owner, 'draft');
        $this->expectException(ValidationException::class);
        $eway->save($draft, ['trans_mode' => 'road', 'distance_km' => 10, 'vehicle_no' => 'MH12AB1234']);
    }

    public function test_screens_download_and_permissions(): void
    {
        $sale = $this->sale($this->gujarat, '100');
        $this->actingAs($this->owner)->get(route('invoices.show', $sale))->assertOk()->assertSee('E-way bill')->assertSee('Needed: goods over');
        $this->get(route('gst.eway-bills.index', ['month' => '2026-10']))->assertOk()->assertSee($sale->displayNumber())->assertSee('Not entered');

        $this->put(route('gst.eway-bills.update', $sale), ['trans_mode' => 'road', 'distance_km' => '380', 'vehicle_no' => 'MH12AB1234'])->assertRedirect(route('invoices.show', $sale));
        $this->get(route('invoices.show', $sale))->assertSee('JSON for the portal');
        $download = $this->get(route('gst.eway-bills.json', ['ids' => [$sale->id]]));
        $download->assertOk();
        $this->assertStringContainsString('attachment; filename="eway-bill-', $download->headers->get('content-disposition'));
        $this->assertSame('MH12AB1234', json_decode($download->getContent(), true)['billLists'][0]['vehicleNo']);

        $this->put(route('gst.eway-bills.update', $sale), ['trans_mode' => 'road', 'distance_km' => '380', 'vehicle_no' => 'MH12AB1234', 'ewb_no' => '381234567890', 'ewb_date' => '2026-10-10', 'valid_till' => '2026-10-12'])
            ->assertSessionHas('success', 'E-way bill 381234567890 saved.');
        $this->assertSame(1, EwayBill::count());
        $this->get(route('gst.eway-bills.index', ['month' => '2026-10']))->assertSee('381234567890')->assertSee('Expired');
        $this->put(route('gst.eway-bills.update', $sale), ['trans_mode' => 'road', 'distance_km' => '5000'])->assertSessionHasErrors('distance_km');

        $viewer = $this->addMember($this->company, 'viewer');
        $this->actingAs($viewer)->get(route('invoices.show', $sale))->assertOk()->assertSee('380 km')->assertDontSee('JSON for the portal');
        $this->actingAs($viewer)->put(route('gst.eway-bills.update', $sale), ['trans_mode' => 'road', 'distance_km' => '1'])->assertForbidden();
    }
}
