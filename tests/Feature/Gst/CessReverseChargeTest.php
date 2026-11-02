<?php

namespace Tests\Feature\Gst;

use App\Models\Account;
use App\Models\AccountGroup;
use App\Models\Invoice;
use App\Models\Party;
use App\Models\StockItem;
use App\Models\Unit;
use App\Services\Billing\InvoiceService;
use App\Services\Gst\GstReports;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/** Company in Maharashtra, September 2026. */
class CessReverseChargeTest extends TestCase
{
    use RefreshDatabase;

    protected $owner;
    protected array $p = [];

    protected function setUp(): void
    {
        parent::setUp();
        [$this->owner] = $this->createCompanyWithOwner(['gstin' => '27AAACD1234E1Z5']);

        $this->p['mumbai'] = $this->party('Mumbai Retail', 'sundry_debtors', 'Maharashtra', 'regular', '27ABCDE1234F1Z5');
        $this->p['lawyer'] = $this->party('Advocate Rao', 'sundry_creditors', 'Maharashtra', 'unregistered');
        $this->p['gta'] = $this->party('Gujarat Roadways', 'sundry_creditors', 'Gujarat', 'regular', '24PQRSX1234F1Z5');
    }

    protected function party(string $name, string $group, string $state, string $reg, ?string $gstin = null): Account
    {
        $ledger = Account::create(['name' => $name, 'account_group_id' => AccountGroup::idFor($group), 'account_type' => 'party', 'maintain_bill_wise' => true]);
        Party::create(['account_id' => $ledger->id, 'state' => $state, 'gst_registration_type' => $reg, 'gstin' => $gstin]);

        return $ledger;
    }

    protected function save(string $kind, string $party, array $items, array $extra = []): Invoice
    {
        return app(InvoiceService::class)->save(null, $kind, array_merge([
            'date' => '2026-09-10',
            'party_account_id' => $this->p[$party]->id,
            'items' => array_map(fn ($i) => array_merge(['account_id' => Account::idFor($kind === 'sales' || $kind === 'credit_note' ? 'sales' : 'purchase'), 'quantity' => '1', 'hsn_sac' => '9982'], $i), $items),
        ], $extra), $this->owner);
    }

    /** Voucher lines as [ledger name => signed rupees, + debit]. */
    protected function entries(Invoice $invoice): array
    {
        return $invoice->voucher->entries()->with('account')->get()
            ->mapWithKeys(fn ($e) => [$e->account->name => (float) $e->debit - (float) $e->credit])->sortKeys()->all();
    }

    public function test_cess_is_charged_and_posted_to_its_own_ledger(): void
    {
        $item = StockItem::create(['name' => 'Cola 300ml', 'unit_id' => Unit::where('symbol', 'Nos')->value('id'), 'hsn_sac' => '2202', 'gst_rate' => '28', 'cess_rate' => '12',
            'sales_account_id' => Account::idFor('sales')]);
        $sale = $this->save('sales', 'mumbai', [['stock_item_id' => $item->id, 'rate' => '10000', 'quantity' => '1']]);

        $this->assertSame(['28.00', '12.00', '1200.00'], [$sale->items[0]->gst_rate, $sale->items[0]->cess_rate, $sale->items[0]->cess_amount]);
        $this->assertSame(['1400.00', '1400.00', '1200.00', '14000.00'], [$sale->cgst_total, $sale->sgst_total, $sale->cess_total, $sale->grand_total]);
        $this->assertSame([
            'Mumbai Retail' => 14000.0, 'Output CGST' => -1400.0, 'Output Cess' => -1200.0, 'Output SGST' => -1400.0, 'Sales' => -10000.0,
        ], $this->entries($sale));
        $this->assertSame('cess', Account::find(Account::idFor('output_cess'))->tax_type);

        // A rate typed on the line wins over the item's; bad rates are refused.
        $override = $this->save('sales', 'mumbai', [['stock_item_id' => $item->id, 'rate' => '100', 'cess_rate' => '0']]);
        $this->assertSame('0.00', $override->cess_total);
        try {
            $this->save('sales', 'mumbai', [['rate' => '100', 'gst_rate' => '18', 'cess_rate' => '600']]);
            $this->fail('A cess rate above 500% was accepted.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('items.0.cess_rate', $e->errors());
        }
    }

    public function test_reverse_charge_purchase_books_credit_and_liability(): void
    {
        // An unregistered supplier charges no GST, unless the service is under reverse charge.
        $plain = $this->save('purchase', 'lawyer', [['rate' => '10000', 'gst_rate' => '18']]);
        $this->assertSame(['none', '0.00', '10000.00'], [$plain->tax_mode, $plain->cgst_total, $plain->grand_total]);

        $rcm = $this->save('purchase', 'lawyer', [['rate' => '10000', 'gst_rate' => '18']], ['reverse_charge' => true, 'reference' => 'FEE-1']);
        $this->assertTrue($rcm->reverse_charge);
        $this->assertSame(['intra', '900.00', '900.00', '10000.00'], [$rcm->tax_mode, $rcm->cgst_total, $rcm->sgst_total, $rcm->grand_total]);
        $this->assertSame([
            'Advocate Rao' => -10000.0, 'CGST Payable (Reverse Charge)' => -900.0, 'Input CGST' => 900.0, 'Input SGST' => 900.0,
            'Purchase' => 10000.0, 'SGST Payable (Reverse Charge)' => -900.0,
        ], $this->entries($rcm));

        // Inter-state GTA freight: IGST; a debit note against it reverses both sides.
        $gta = $this->save('purchase', 'gta', [['rate' => '20000', 'gst_rate' => '5']], ['reverse_charge' => true, 'reference' => 'LR-77']);
        $this->assertSame(['inter', '1000.00', '20000.00'], [$gta->tax_mode, $gta->igst_total, $gta->grand_total]);
        $note = $this->save('debit_note', 'gta', [['rate' => '2000', 'gst_rate' => '5']], ['reverse_charge' => true, 'original_invoice_id' => $gta->id]);
        $this->assertSame(['Gujarat Roadways' => 2000.0, 'IGST Payable (Reverse Charge)' => 100.0, 'Input IGST' => -100.0, 'Purchase' => -2000.0], $this->entries($note));

        // Saved input round-trips the flag, so posting or altering keeps it.
        $this->assertTrue(app(InvoiceService::class)->toInput($rcm)['reverse_charge']);
    }

    public function test_reverse_charge_sale_leaves_tax_to_the_recipient(): void
    {
        $sale = $this->save('sales', 'mumbai', [['rate' => '50000', 'gst_rate' => '5']], ['reverse_charge' => true]);

        $this->assertSame(['1250.00', '1250.00', '50000.00'], [$sale->cgst_total, $sale->sgst_total, $sale->grand_total]);
        $this->assertSame(['Mumbai Retail' => 50000.0, 'Sales' => -50000.0], $this->entries($sale));
    }

    public function test_gst_returns_show_reverse_charge_and_cess(): void
    {
        $this->save('sales', 'mumbai', [['rate' => '10000', 'gst_rate' => '28', 'cess_rate' => '12']]);
        $this->save('sales', 'mumbai', [['rate' => '50000', 'gst_rate' => '5']], ['reverse_charge' => true]);
        $this->save('purchase', 'lawyer', [['rate' => '10000', 'gst_rate' => '18']], ['reverse_charge' => true]);
        $this->save('purchase', 'gta', [['rate' => '20000', 'gst_rate' => '5']], ['reverse_charge' => true]);
        $this->save('purchase', 'gta', [['rate' => '1000', 'gst_rate' => '18', 'cess_rate' => '1']]);

        $r = app(GstReports::class)->gstr3b('2026-09-01', '2026-09-30');
        $this->assertSame(['taxable' => 1000000, 'igst' => 0, 'cgst' => 140000, 'sgst' => 140000, 'cess' => 120000], $r['outward']['taxable']);
        $this->assertSame(5000000, $r['outward']['rcm_outward']['taxable']);
        $this->assertSame(['taxable' => 3000000, 'igst' => 100000, 'cgst' => 90000, 'sgst' => 90000, 'cess' => 0], $r['inward_rcm']);
        $this->assertSame(['igst' => 100000, 'cgst' => 90000, 'sgst' => 90000, 'cess' => 0], $r['itc']['rcm']);
        $this->assertSame(['igst' => 18000, 'cgst' => 0, 'sgst' => 0, 'cess' => 1000], $r['itc']['other']);
        $this->assertSame(['igst' => 118000, 'cgst' => 90000, 'sgst' => 90000, 'cess' => 1000], $r['itc']['net']);
        // Cess credit only against cess: 1,200 - 10 = 1,190 in cash.
        $this->assertSame(119000, $r['setoff']['cash']['cess']);
        $this->assertSame(1000, $r['setoff']['used']['cess']['cess']);
        // Reverse charge tax (2,800) is always paid in cash on top of the set-off balance.
        $this->assertSame(array_sum($r['setoff']['cash']) + 280000, $r['cash_total']);

        $g1 = app(GstReports::class)->gstr1('2026-09-01', '2026-09-30');
        $this->assertSame([false, true], array_column($g1['b2b'], 'reverse_charge'));
        $this->assertSame(120000, $g1['b2b'][0]['rates'][0]['cess']);

        $register = app(GstReports::class)->purchaseRegister('2026-09-01', '2026-09-30');
        $this->assertSame([true, true, false], $register->pluck('reverse_charge')->all());
        $this->assertSame(1000, $register[2]['cess']);
    }

    public function test_screens_and_exports(): void
    {
        $this->actingAs($this->owner);

        $this->get(route('invoices.create', ['kind' => 'purchase']))->assertOk()->assertSee('Reverse charge')->assertSee('Cess %');
        $this->post(route('invoices.store'), [
            'kind' => 'purchase', 'date' => '2026-09-10', 'party_account_id' => $this->p['lawyer']->id, 'reverse_charge' => '1', 'reference' => 'FEE-2',
            'items' => [['account_id' => Account::idFor('purchase'), 'description' => 'Legal fees', 'hsn_sac' => '9982', 'quantity' => '1', 'rate' => '5000', 'gst_rate' => '18']],
            'action' => 'post',
        ])->assertRedirect();
        $bill = Invoice::query()->latest('id')->first();
        $this->assertTrue($bill->reverse_charge);
        $this->get(route('invoices.show', $bill))->assertOk()->assertSee('Reverse charge')->assertSee('not part of the total');
        $this->get(route('invoices.print', $bill))->assertOk()->assertSee('Tax payable on reverse charge:</strong> Yes', false);

        $this->get(route('gst.gstr3b', ['from' => '2026-09-01', 'to' => '2026-09-30']))->assertOk()->assertSee('Inward supplies liable to reverse charge')->assertSee('(A)(3)');
        $csv = $this->get(route('gst.purchase-register', ['from' => '2026-09-01', 'to' => '2026-09-30', 'export' => 'csv']))->streamedContent();
        $this->assertStringContainsString('"Advocate Rao",Maharashtra,Y,5000.00,5000.00,0.00,450.00,450.00,0.00', $csv);
    }

    public function test_an_existing_tax_ledger_of_the_same_name_is_used(): void
    {
        $mine = Account::create(['name' => 'Output Cess', 'account_group_id' => AccountGroup::idFor('duties_taxes'), 'account_type' => 'tax', 'tax_type' => 'cess']);
        $sale = $this->save('sales', 'mumbai', [['rate' => '1000', 'gst_rate' => '28', 'cess_rate' => '15']]);

        $this->assertSame($mine->id, Account::idFor('output_cess'));
        $this->assertSame(-150.0, $this->entries($sale)['Output Cess']);
    }
}
