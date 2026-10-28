<?php

namespace Tests\Feature\Gst;

use App\Models\Account;
use App\Models\AccountGroup;
use App\Models\Party;
use App\Services\Billing\InvoiceService;
use App\Services\Gst\GstReports;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * September 2026, company in Maharashtra:
 *   Sales: Mumbai (27, B2B) 1,000 @18% · Surat (24, B2B) 1,500 @12% · Bengaluru consumer 1,50,000 @18% (B2CL)
 *          Pune consumer 2,000 @5% + 300 @0% (B2CS / nil) · credit note to Mumbai 100 @18%
 *   Purchases: Pune supplier 5,000 @18% · Ahmedabad supplier 2,000 @18% · debit note to Pune 1,000 @18%
 */
class GstReportsTest extends TestCase
{
    use RefreshDatabase;

    protected $owner;
    protected array $p = [];
    protected array $docs = [];

    protected function setUp(): void
    {
        parent::setUp();
        [$this->owner] = $this->createCompanyWithOwner(['gstin' => '27AAACD1234E1Z5']);

        $this->p['mumbai'] = $this->party('Mumbai Retail', 'sundry_debtors', 'Maharashtra', 'regular', '27ABCDE1234F1Z5');
        $this->p['surat'] = $this->party('Surat Textiles', 'sundry_debtors', 'Gujarat', 'regular', '24ABCDE1234F1Z5');
        $this->p['blr'] = $this->party('Bengaluru Buyer', 'sundry_debtors', 'Karnataka', 'consumer');
        $this->p['pune'] = $this->party('Pune Walk-in', 'sundry_debtors', 'Maharashtra', 'consumer');
        $this->p['supplier'] = $this->party('Pune Supplies', 'sundry_creditors', 'Maharashtra', 'regular', '27PQRSX1234F1Z5');
        $this->p['ahd'] = $this->party('Ahmedabad Metals', 'sundry_creditors', 'Gujarat', 'regular', '24PQRSX1234F1Z5');

        $this->docs['mumbai'] = $this->save('sales', 'mumbai', [['1000', '18', '8471', 'sales']]);
        $this->save('sales', 'surat', [['500', '12', '5208', 'sales', '3']]);
        $this->save('sales', 'blr', [['150000', '18', '8471', 'sales']]);
        $this->save('sales', 'pune', [['2000', '5', '6109', 'sales'], ['300', '0', '0401', 'sales']]);
        $this->save('credit_note', 'mumbai', [['100', '18', '8471', 'sales']], ['original_invoice_id' => $this->docs['mumbai']->id]);
        $this->docs['bill'] = $this->save('purchase', 'supplier', [['5000', '18', '8471', 'purchase']], ['reference' => 'PS-1']);
        $this->save('purchase', 'ahd', [['2000', '18', '7208', 'purchase']], ['reference' => 'AM-9']);
        $this->save('debit_note', 'supplier', [['1000', '18', '8471', 'purchase']], ['original_invoice_id' => $this->docs['bill']->id]);
        // INV/0005 is outside the period and INV/0006 is cancelled: neither counts.
        $this->save('sales', 'mumbai', [['999', '18', '8471', 'sales']], ['date' => '2026-08-31']);
        $cancelled = $this->save('sales', 'mumbai', [['777', '18', '8471', 'sales']]);
        app(InvoiceService::class)->cancel($cancelled, $this->owner, 'test');
    }

    protected function party(string $name, string $group, string $state, string $reg, ?string $gstin = null): Account
    {
        $ledger = Account::create(['name' => $name, 'account_group_id' => AccountGroup::idFor($group), 'account_type' => 'party', 'maintain_bill_wise' => true]);
        Party::create(['account_id' => $ledger->id, 'state' => $state, 'gst_registration_type' => $reg, 'gstin' => $gstin]);

        return $ledger;
    }

    protected function save(string $kind, string $party, array $items, array $extra = [])
    {
        return app(InvoiceService::class)->save(null, $kind, array_merge([
            'date' => '2026-09-10',
            'party_account_id' => $this->p[$party]->id,
            'items' => array_map(fn ($i) => ['account_id' => Account::idFor($i[3]), 'rate' => $i[0], 'gst_rate' => $i[1], 'hsn_sac' => $i[2], 'quantity' => $i[4] ?? '1', 'unit' => 'Nos'], $items),
        ], $extra), $this->owner);
    }

    public function test_gstr1_sections(): void
    {
        $r = app(GstReports::class)->gstr1('2026-09-01', '2026-09-30');

        $this->assertSame(['Mumbai Retail', 'Surat Textiles'], array_column($r['b2b'], 'party'));
        $this->assertSame([['rate' => '18', 'taxable' => 100000, 'igst' => 0, 'cgst' => 9000, 'sgst' => 9000, 'cess' => 0]], $r['b2b'][0]['rates']);
        $this->assertSame('24', $r['b2b'][1]['state_code']);
        $this->assertSame(18000, $r['b2b'][1]['rates'][0]['igst']);

        $this->assertCount(1, $r['b2cl']);
        $this->assertSame(2700000, $r['b2cl'][0]['rates'][0]['igst']);

        $this->assertSame([
            ['place_of_supply' => 'Maharashtra', 'state_code' => '27', 'rate' => '0', 'taxable' => 30000, 'igst' => 0, 'cgst' => 0, 'sgst' => 0, 'cess' => 0],
            ['place_of_supply' => 'Maharashtra', 'state_code' => '27', 'rate' => '5', 'taxable' => 200000, 'igst' => 0, 'cgst' => 5000, 'sgst' => 5000, 'cess' => 0],
        ], $r['b2cs']);

        $this->assertCount(1, $r['cdnr']);
        $this->assertSame('INV/0001', $r['cdnr'][0]['original']);
        $this->assertSame([], $r['cdnur']);

        $hsn8471 = collect($r['hsn'])->firstWhere('hsn', '8471');
        $this->assertSame(100000 + 15000000 - 10000, $hsn8471['taxable']); // 1,000 + 1,50,000 - 100
        $this->assertSame(1000, $hsn8471['quantity']); // 1 + 1 - 1 (thousandths)

        $docs = collect($r['documents'])->keyBy('kind');
        $this->assertSame(['INV/0001', 'INV/0006', 5, 1], [$docs['sales']['from'], $docs['sales']['to'], $docs['sales']['total'], $docs['sales']['cancelled']]);
    }

    public function test_gstr3b_and_set_off(): void
    {
        $r = app(GstReports::class)->gstr3b('2026-09-01', '2026-09-30');

        $this->assertSame(['taxable' => 15440000, 'igst' => 2718000, 'cgst' => 13100, 'sgst' => 13100, 'cess' => 0], $r['outward']['taxable']);
        $this->assertSame(30000, $r['outward']['nil_exempt']['taxable']);
        $this->assertSame(['igst' => 36000, 'cgst' => 45000, 'sgst' => 45000, 'cess' => 0], array_diff_key($r['itc']['available'], ['taxable' => 0]));
        $this->assertSame(['igst' => 36000, 'cgst' => 36000, 'sgst' => 36000, 'cess' => 0], $r['itc']['net']);

        // IGST 27,180 - IGST credit 360 - CGST credit left 229 - SGST credit left 229 = 26,362 in cash.
        $this->assertSame(['igst' => 2636200, 'cgst' => 0, 'sgst' => 0, 'cess' => 0], $r['setoff']['cash']);
        $this->assertSame(['igst' => 0, 'cgst' => 0, 'sgst' => 0, 'cess' => 0], $r['setoff']['carried_forward']);
    }

    public function test_set_off_never_crosses_cgst_and_sgst(): void
    {
        $r = app(GstReports::class)->setOff(['igst' => 0, 'cgst' => 1000, 'sgst' => 0], ['igst' => 300, 'cgst' => 0, 'sgst' => 5000]);

        $this->assertSame(['igst' => 0, 'cgst' => 700, 'sgst' => 0, 'cess' => 0], $r['cash']);
        $this->assertSame(['igst' => 0, 'cgst' => 0, 'sgst' => 5000, 'cess' => 0], $r['carried_forward']);
    }

    public function test_exports_are_zero_rated(): void
    {
        $this->p['us'] = $this->party('US Client', 'sundry_debtors', 'Maharashtra', 'overseas');
        $this->save('sales', 'us', [['10000', '18', '9983', 'sales']]);

        $this->assertCount(1, app(GstReports::class)->gstr1('2026-09-01', '2026-09-30')['exports']);
        $this->assertSame(180000, app(GstReports::class)->gstr3b('2026-09-01', '2026-09-30')['outward']['zero_rated']['igst']);
    }

    public function test_screens_and_exports(): void
    {
        $this->actingAs($this->owner);

        $this->get(route('gst.gstr1', ['month' => '2026-09']))->assertOk()->assertSee('Mumbai Retail')->assertSee('27ABCDE1234F1Z5');
        $this->get(route('gst.gstr3b', ['month' => '2026-09']))->assertOk()->assertSee('26,362.00');
        $this->get(route('gst.purchase-register', ['month' => '2026-09']))->assertOk()->assertSee('PS-1')->assertSee('AM-9');
        foreach (['b2b', 'b2cl', 'b2cs', 'cdnr', 'hsn', 'docs'] as $section) {
            $this->get(route('gst.gstr1', ['month' => '2026-09', 'export' => 'csv', 'section' => $section]))->assertOk();
        }
        $csv = $this->get(route('gst.purchase-register', ['from' => '2026-09-01', 'to' => '2026-09-30', 'export' => 'csv']))->streamedContent();
        $this->assertStringContainsString('-1180.00', $csv); // debit note shown negative

        $employee = $this->addMember(\App\Models\Company::first(), 'employee');
        $this->actingAs($employee)->get(route('gst.gstr1'))->assertForbidden();
    }

    public function test_gstr1_json_for_the_portal(): void
    {
        [$json, $errors] = app(\App\Services\Gst\Gstr1Json::class)->build('2026-09-01', '2026-09-30');
        $this->assertSame([], $errors);
        $this->assertSame(['27AAACD1234E1Z5', '092026'], [$json['gstin'], $json['fp']]);

        $this->assertSame(['27ABCDE1234F1Z5', '24ABCDE1234F1Z5'], array_column($json['b2b'], 'ctin'));
        $mumbai = $json['b2b'][0]['inv'][0];
        $this->assertSame(['10-09-2026', 1180.0, '27', 'N', 'R'], [$mumbai['idt'], $mumbai['val'], $mumbai['pos'], $mumbai['rchrg'], $mumbai['inv_typ']]);
        $this->assertSame([['num' => 1, 'itm_det' => ['rt' => 18.0, 'txval' => 1000.0, 'iamt' => 0.0, 'camt' => 90.0, 'samt' => 90.0, 'csamt' => 0.0]]], $mumbai['itms']);
        $this->assertSame(['rt' => 12.0, 'txval' => 1500.0, 'iamt' => 180.0, 'camt' => 0.0, 'samt' => 0.0, 'csamt' => 0.0], $json['b2b'][1]['inv'][0]['itms'][0]['itm_det']);

        $this->assertSame('29', $json['b2cl'][0]['pos']);
        $this->assertSame(['rt' => 18.0, 'txval' => 150000.0, 'iamt' => 27000.0, 'csamt' => 0.0], $json['b2cl'][0]['inv'][0]['itms'][0]['itm_det']);
        $this->assertSame([['sply_ty' => 'INTRA', 'pos' => '27', 'typ' => 'OE', 'rt' => 5.0, 'txval' => 2000.0, 'camt' => 50.0, 'samt' => 50.0, 'csamt' => 0.0]], $json['b2cs']);
        $this->assertSame([['sply_ty' => 'INTRAB2C', 'expt_amt' => 0, 'nil_amt' => 300.0, 'ngsup_amt' => 0]], $json['nil']['inv']);

        $this->assertSame('27ABCDE1234F1Z5', $json['cdnr'][0]['ctin']);
        $this->assertSame(['C', 118.0], [$json['cdnr'][0]['nt'][0]['ntty'], $json['cdnr'][0]['nt'][0]['val']]);
        $this->assertArrayNotHasKey('exp', $json);

        $hsn = collect($json['hsn']['data'])->keyBy('hsn_sc');
        $this->assertSame(['0401', '5208', '6109', '8471'], array_column($json['hsn']['data'], 'hsn_sc'));
        $this->assertSame([150900.0, 'NOS', 3.0], [$hsn['8471']['txval'], $hsn['8471']['uqc'], $hsn['5208']['qty']]);

        $docs = collect($json['doc_issue']['doc_det'])->keyBy('doc_num');
        $this->assertSame([5, 1, 4], [$docs[1]['docs'][0]['totnum'], $docs[1]['docs'][0]['cancel'], $docs[1]['docs'][0]['net_issue']]);
        $this->assertSame(1, $docs[5]['docs'][0]['totnum']);

        // Only whole months or quarters, and only with the company GSTIN.
        $this->assertNotSame([], app(\App\Services\Gst\Gstr1Json::class)->build('2026-09-05', '2026-09-30')[1]);
        $this->assertSame([], app(\App\Services\Gst\Gstr1Json::class)->build('2026-07-01', '2026-09-30')[1]);
        app(\App\Support\CurrentCompany::class)->get()->update(['gstin' => null]);
        $this->assertStringContainsString('GSTIN', app(\App\Services\Gst\Gstr1Json::class)->build('2026-09-01', '2026-09-30')[1][0]);
    }

    public function test_gstr1_json_download(): void
    {
        $this->actingAs($this->owner);
        $this->get(route('gst.gstr1', ['month' => '2026-09']))->assertOk()->assertSee('JSON for the GST portal');
        $response = $this->get(route('gst.gstr1-json', ['from' => '2026-09-01', 'to' => '2026-09-30']))->assertOk();
        $this->assertStringContainsString('GSTR1_27AAACD1234E1Z5_092026.json', $response->headers->get('content-disposition'));
        $this->assertSame('092026', json_decode($response->streamedContent(), true)['fp']);

        $this->get(route('gst.gstr1-json', ['from' => '2026-09-01', 'to' => '2026-09-15']))->assertRedirect()->assertSessionHas('error');

        $viewer = $this->addMember(app(\App\Support\CurrentCompany::class)->get(), 'viewer');
        $this->actingAs($viewer)->get(route('gst.gstr1-json', ['from' => '2026-09-01', 'to' => '2026-09-30']))->assertForbidden();
    }
}
