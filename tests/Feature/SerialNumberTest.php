<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Company;
use App\Models\StockItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SerialNumberTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected Account $sales;

    protected Account $purchases;

    protected Account $supplier;

    protected Account $customer;

    protected StockItem $phone;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = User::factory()->create()->companies()->create([
            'name' => 'Mobile Hub', 'gstin' => '27AAAAA0000A1Z5', 'financial_year' => '2026-27', 'currency' => 'INR',
        ]);
        $group = fn (string $code) => $this->company->accountGroups()->where('code', $code)->value('id');
        $this->sales = $this->company->accounts()->create(['name' => 'Sales', 'account_group_id' => $group('SALES_ACCOUNTS'), 'account_type' => 'income']);
        $this->purchases = $this->company->accounts()->create(['name' => 'Purchases', 'account_group_id' => $group('PURCHASE_ACCOUNTS'), 'account_type' => 'expenses']);
        $this->supplier = $this->company->accounts()->create(['name' => 'Phone Distributor', 'account_group_id' => $group('SUNDRY_CREDITORS'), 'account_type' => 'liabilities', 'state_code' => '27']);
        $this->customer = $this->company->accounts()->create(['name' => 'Rahul', 'account_group_id' => $group('SUNDRY_DEBTORS'), 'account_type' => 'assets', 'state_code' => '27']);
        $this->phone = $this->company->stockItems()->create([
            'name' => 'Phone X', 'unit_id' => $this->company->units()->where('symbol', 'Nos')->value('id'), 'track_serials' => true,
        ]);

        $this->bill('purchase', $this->supplier, $this->purchases, 3, "imei001\nIMEI002, imei003")->assertSessionHasNoErrors();
    }

    protected function bill(string $type, Account $party, Account $ledger, float $qty, ?string $serials)
    {
        return $this->actingAs($this->company->user)
            ->post(route('companies.gst-invoices.store', $this->company), [
                'type' => $type, 'date' => '2026-07-10', 'party_account_id' => $party->id,
                'lines' => [[
                    'account_id' => $ledger->id, 'stock_item_id' => $this->phone->id, 'quantity' => $qty, 'item_rate' => 15000,
                    'hsn_sac' => '8517', 'rate' => 18, 'serial_numbers' => $serials,
                ]],
            ]);
    }

    public function test_purchase_records_each_unit(): void
    {
        $this->assertSame(['IMEI001', 'IMEI002', 'IMEI003'], $this->phone->serialNumbers()->orderBy('serial')->pluck('serial')->all());
        $this->assertSame(3, $this->phone->serialNumbers()->whereNull('out_voucher_id')->count());
    }

    public function test_sale_takes_serials_out_and_prints_them(): void
    {
        $this->bill('sales', $this->customer, $this->sales, 1, 'imei002')->assertSessionHasNoErrors();

        $sold = $this->phone->serialNumbers()->where('serial', 'IMEI002')->firstOrFail();
        $sale = $this->company->vouchers()->where('type', 'sales')->firstOrFail();
        $this->assertSame($sale->id, $sold->out_voucher_id);

        $this->actingAs($this->company->user)
            ->get(route('companies.gst-invoices.show', [$this->company, $sale]))
            ->assertSee('S/N: IMEI002');

        // Lookup shows where it came from and went.
        $this->actingAs($this->company->user)
            ->get(route('companies.inventory.serials', [$this->company, 'q' => 'imei002']))
            ->assertOk()
            ->assertSee('Phone Distributor')
            ->assertSee('Rahul')
            ->assertSee('Sold');
    }

    public function test_count_and_stock_rules(): void
    {
        $this->bill('sales', $this->customer, $this->sales, 2, 'IMEI001')
            ->assertSessionHasErrors(['lines.0.serial_numbers' => 'Line 1: enter 2 serial number(s) for Phone X (found 1).']);
        $this->bill('sales', $this->customer, $this->sales, 1, 'IMEI999')
            ->assertSessionHasErrors(['lines.0.serial_numbers' => 'Line 1: IMEI999 is not in stock.']);
        $this->bill('purchase', $this->supplier, $this->purchases, 1, 'IMEI001')
            ->assertSessionHasErrors(['lines.0.serial_numbers' => 'Line 1: IMEI001 is already recorded.']);

        $this->bill('sales', $this->customer, $this->sales, 1, 'IMEI001')->assertSessionHasNoErrors();
        $this->bill('sales', $this->customer, $this->sales, 1, 'IMEI001')
            ->assertSessionHasErrors(['lines.0.serial_numbers' => 'Line 1: IMEI001 is not in stock.']);
    }

    public function test_sales_return_brings_unit_back_and_deleting_sale_too(): void
    {
        $this->bill('sales', $this->customer, $this->sales, 1, 'IMEI003');
        $this->bill('credit_note', $this->customer, $this->sales, 1, 'IMEI003')->assertSessionHasNoErrors();
        $this->assertNull($this->phone->serialNumbers()->where('serial', 'IMEI003')->value('out_voucher_id'));

        // A unit that was never sold can't be returned.
        $this->bill('credit_note', $this->customer, $this->sales, 1, 'IMEI001')
            ->assertSessionHasErrors(['lines.0.serial_numbers' => "Line 1: IMEI001 was not sold, so it can't come back."]);

        // Deleting a sale puts its unit back in stock.
        $this->bill('sales', $this->customer, $this->sales, 1, 'IMEI002');
        $sale = $this->company->vouchers()->where('type', 'sales')->latest('id')->firstOrFail();
        $this->actingAs($this->company->user)->delete(route('companies.vouchers.destroy', [$this->company, $sale]));
        $this->assertNull($this->phone->serialNumbers()->where('serial', 'IMEI002')->value('out_voucher_id'));
    }

    public function test_items_without_serial_tracking_need_none(): void
    {
        $this->phone->update(['track_serials' => false]);

        $this->bill('sales', $this->customer, $this->sales, 2, null)->assertSessionHasNoErrors();
    }
}
