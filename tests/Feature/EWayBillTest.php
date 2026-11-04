<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EWayBillTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected Account $sales;

    protected Account $purchases;

    protected Account $buyer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = User::factory()->create()->companies()->create([
            'name' => 'Acme Traders', 'gstin' => '27AAAAA0000A1Z5', 'address' => '12 MG Road', 'city' => 'Pune',
            'pincode' => '411001', 'financial_year' => '2026-27', 'currency' => 'INR',
        ]);

        $group = fn (string $code) => $this->company->accountGroups()->where('code', $code)->value('id');
        $this->sales = $this->company->accounts()->create(['name' => 'Sales', 'account_group_id' => $group('SALES_ACCOUNTS'), 'account_type' => 'income']);
        $this->purchases = $this->company->accounts()->create(['name' => 'Purchases', 'account_group_id' => $group('PURCHASE_ACCOUNTS'), 'account_type' => 'expenses']);
        $this->buyer = $this->company->accounts()->create([
            'name' => 'Bengaluru Tech', 'account_group_id' => $group('SUNDRY_DEBTORS'), 'account_type' => 'assets',
            'gstin' => '29CCCCC2222C1Z5', 'state_code' => '29', 'address' => '5 Residency Road', 'city' => 'Bengaluru', 'pincode' => '560025',
        ]);
    }

    protected function invoice(string $type = 'sales', ?Account $ledger = null, ?Account $party = null)
    {
        $item = $this->company->stockItems()->create(['name' => 'Laptop', 'unit_id' => $this->company->units()->where('symbol', 'Nos')->value('id')]);

        $this->actingAs($this->company->user)
            ->post(route('companies.gst-invoices.store', $this->company), [
                'type' => $type, 'date' => '2026-08-14', 'party_account_id' => ($party ?? $this->buyer)->id,
                'lines' => [['account_id' => ($ledger ?? $this->sales)->id, 'stock_item_id' => $item->id, 'quantity' => 2, 'item_rate' => 40000, 'hsn_sac' => '8471', 'rate' => 18]],
            ])
            ->assertSessionHasNoErrors();

        return $this->company->vouchers()->latest('id')->firstOrFail();
    }

    protected function download($voucher, array $transport)
    {
        return $this->actingAs($this->company->user)
            ->from(route('companies.gst-invoices.show', [$this->company, $voucher]))
            ->post(route('companies.gst-invoices.e-way-bill', [$this->company, $voucher]), $transport);
    }

    public function test_sales_e_way_bill_json(): void
    {
        $voucher = $this->invoice();

        $response = $this->download($voucher, ['mode' => '1', 'distance' => 840, 'vehicle_no' => 'mh 12 ab 1234', 'transporter_name' => 'Fast Movers'])->assertOk();
        $bill = json_decode($response->streamedContent(), true)['billLists'][0];

        $this->assertSame(['O', 1, 'INV', '1/2026-27', '14/08/2026'], [$bill['supplyType'], $bill['subSupplyType'], $bill['docType'], $bill['docNo'], $bill['docDate']]);
        $this->assertSame(['27AAAAA0000A1Z5', 411001, 27], [$bill['fromGstin'], $bill['fromPincode'], $bill['fromStateCode']]);
        $this->assertSame(['29CCCCC2222C1Z5', 560025, 29], [$bill['toGstin'], $bill['toPincode'], $bill['toStateCode']]);
        $this->assertEquals([80000, 14400, 94400], [$bill['totalValue'], $bill['igstValue'], $bill['totInvValue']]);
        $this->assertSame(['MH12AB1234', '840', 1, 8471], [$bill['vehicleNo'], $bill['transDistance'], $bill['transMode'], $bill['mainHsnCode']]);
        $this->assertEquals(['hsnCode' => 8471, 'qtyUnit' => 'NOS', 'igstRate' => 18, 'cgstRate' => 0], array_intersect_key($bill['itemList'][0], array_flip(['hsnCode', 'qtyUnit', 'igstRate', 'cgstRate'])));
    }

    public function test_purchase_is_inward_with_supplier_as_from(): void
    {
        $voucher = $this->invoice('purchase', $this->purchases);

        $bill = json_decode($this->download($voucher, ['mode' => '1', 'distance' => 840, 'vehicle_no' => 'KA01AB1234'])->streamedContent(), true)['billLists'][0];

        $this->assertSame(['I', 'BIL', '29CCCCC2222C1Z5', '27AAAAA0000A1Z5'], [$bill['supplyType'], $bill['docType'], $bill['fromGstin'], $bill['toGstin']]);
    }

    public function test_unregistered_buyer_uses_urp(): void
    {
        $walkIn = $this->company->accounts()->create([
            'name' => 'Walk-in', 'account_group_id' => $this->buyer->account_group_id, 'account_type' => 'assets',
            'state_code' => '27', 'address' => 'FC Road', 'city' => 'Pune', 'pincode' => '411004',
        ]);
        $voucher = $this->invoice(party: $walkIn);

        $bill = json_decode($this->download($voucher, ['mode' => '1', 'distance' => 5, 'vehicle_no' => 'MH12AB1234'])->streamedContent(), true)['billLists'][0];

        $this->assertSame(['URP', 27], [$bill['toGstin'], $bill['toStateCode']]);
        $this->assertEquals(9, $bill['itemList'][0]['cgstRate']);
    }

    public function test_missing_transport_or_address_is_listed(): void
    {
        $this->buyer->update(['pincode' => null]);
        $voucher = $this->invoice();

        $this->download($voucher, ['mode' => '1', 'distance' => 0])
            ->assertRedirect()
            ->assertSessionHas('ewaybill_problems', fn ($p) => in_array('Party PIN code is missing.', $p)
                && in_array('Approximate distance must be between 1 and 4000 km.', $p)
                && in_array('Enter the vehicle number or the transporter ID.', $p));

        $this->download($voucher, ['mode' => '1', 'distance' => 10, 'vehicle_no' => '123'])
            ->assertSessionHas('ewaybill_problems', fn ($p) => in_array('Vehicle number looks wrong (e.g. MH12AB1234).', $p));
    }

    public function test_invoice_page_shows_form_and_flags_value_over_threshold(): void
    {
        $this->actingAs($this->company->user)
            ->get(route('companies.gst-invoices.show', [$this->company, $this->invoice()]))
            ->assertOk()
            ->assertSee('e-Way Bill JSON')
            ->assertSee('Needed: value over ₹50,000');
    }
}
