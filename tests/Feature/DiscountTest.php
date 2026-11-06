<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Company;
use App\Models\StockItem;
use App\Models\User;
use App\Support\EInvoice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DiscountTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected Account $sales;

    protected Account $customer;

    protected StockItem $laptop;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = User::factory()->create()->companies()->create([
            'name' => 'Acme Traders', 'gstin' => '27AAAAA0000A1Z5', 'address' => 'MG Road', 'city' => 'Pune', 'pincode' => '411001',
            'financial_year' => '2026-27', 'currency' => 'INR',
        ]);
        $group = fn (string $code) => $this->company->accountGroups()->where('code', $code)->value('id');
        $this->sales = $this->company->accounts()->create(['name' => 'Sales', 'account_group_id' => $group('SALES_ACCOUNTS'), 'account_type' => 'income']);
        $this->customer = $this->company->accounts()->create([
            'name' => 'Pune Stores', 'account_group_id' => $group('SUNDRY_DEBTORS'), 'account_type' => 'assets',
            'gstin' => '27BBBBB1111B1Z5', 'state_code' => '27', 'address' => 'FC Road', 'city' => 'Pune', 'pincode' => '411004',
        ]);
        $this->laptop = $this->company->stockItems()->create([
            'name' => 'Laptop', 'unit_id' => $this->company->units()->where('symbol', 'Nos')->value('id'), 'opening_quantity' => 10, 'opening_rate' => 40000,
        ]);
    }

    protected function invoice(array $lines)
    {
        return $this->actingAs($this->company->user)
            ->post(route('companies.gst-invoices.store', $this->company), [
                'type' => 'sales', 'date' => '2026-07-01', 'party_account_id' => $this->customer->id, 'lines' => $lines,
            ]);
    }

    public function test_discount_comes_off_before_gst(): void
    {
        $this->invoice([
            ['account_id' => $this->sales->id, 'stock_item_id' => $this->laptop->id, 'quantity' => 2, 'item_rate' => 50000, 'discount_percent' => 10, 'hsn_sac' => '8471', 'rate' => 18],
            ['account_id' => $this->sales->id, 'taxable' => 2000, 'discount_percent' => 5, 'hsn_sac' => '998314', 'rate' => 18],
        ])->assertSessionHasNoErrors();

        $voucher = $this->company->vouchers()->firstOrFail();
        [$goods, $service] = $voucher->gstLines()->orderBy('id')->get()->all();

        $this->assertSame(['100000.00', '10.00', '90000.00', '8100.00'], [$goods->gross_amount, $goods->discount_percent, $goods->taxable, $goods->cgst]);
        $this->assertSame(['2000.00', '1900.00', '171.00'], [$service->gross_amount, $service->taxable, $service->cgst]);
        // (90,000 + 1,900) × 1.18 = 1,08,442
        $this->assertSame('108442.00', $voucher->amount);
        $this->assertSame('91900.00', $voucher->entries()->where('account_id', $this->sales->id)->value('credit'));
        // Stock leaves at the discounted value.
        $this->assertSame('90000.00', $this->company->stockMovements()->value('amount'));

        $this->actingAs($this->company->user)
            ->get(route('companies.gst-invoices.show', [$this->company, $voucher]))
            ->assertSee('1,00,000.00 less 10% discount');
    }

    public function test_bill_discount_comes_off_every_line_before_gst(): void
    {
        $this->actingAs($this->company->user)
            ->post(route('companies.gst-invoices.store', $this->company), [
                'type' => 'sales', 'date' => '2026-07-01', 'party_account_id' => $this->customer->id, 'bill_discount_percent' => 5,
                'lines' => [
                    ['account_id' => $this->sales->id, 'stock_item_id' => $this->laptop->id, 'quantity' => 2, 'item_rate' => 50000, 'discount_percent' => 10, 'hsn_sac' => '8471', 'rate' => 18],
                    ['account_id' => $this->sales->id, 'taxable' => 2000, 'hsn_sac' => '998314', 'rate' => 12],
                ],
            ])
            ->assertSessionHasNoErrors();

        $voucher = $this->company->vouchers()->firstOrFail();
        [$goods, $service] = $voucher->gstLines()->orderBy('id')->get()->all();

        // 1,00,000 less 10% = 90,000, less 5% = 85,500; 2,000 less 5% = 1,900.
        $this->assertSame(['85500.00', '7695.00', '1900.00', '114.00'], [$goods->taxable, $goods->cgst, $service->taxable, $service->cgst]);
        $this->assertSame('5.00', $voucher->bill_discount_percent);
        // 85,500 × 1.18 + 1,900 × 1.12 = 1,00,890 + 2,128
        $this->assertSame('103018.00', $voucher->amount);
        $this->assertEquals(14500, EInvoice::build($voucher->load('gstLines', 'party', 'company'))['ItemList'][0]['Discount']);

        $this->actingAs($this->company->user)
            ->get(route('companies.gst-invoices.show', [$this->company, $voucher]))
            ->assertSee('after a 5% discount on the whole bill');

        $this->actingAs($this->company->user)
            ->post(route('companies.gst-invoices.store', $this->company), [
                'type' => 'sales', 'date' => '2026-07-01', 'party_account_id' => $this->customer->id, 'bill_discount_percent' => 100,
                'lines' => [['account_id' => $this->sales->id, 'taxable' => 100, 'rate' => 18]],
            ])
            ->assertSessionHasErrors('bill_discount_percent');
    }

    public function test_e_invoice_reports_gross_and_discount(): void
    {
        $this->invoice([
            ['account_id' => $this->sales->id, 'stock_item_id' => $this->laptop->id, 'quantity' => 2, 'item_rate' => 50000, 'discount_percent' => 10, 'hsn_sac' => '8471', 'rate' => 18],
        ]);
        $voucher = $this->company->vouchers()->with('gstLines', 'party', 'company')->firstOrFail();

        $item = EInvoice::build($voucher)['ItemList'][0];
        $this->assertEquals([50000, 100000, 10000, 90000], [$item['UnitPrice'], $item['TotAmt'], $item['Discount'], $item['AssAmt']]);
    }

    public function test_discount_carries_from_estimate_to_invoice_and_is_validated(): void
    {
        $this->actingAs($this->company->user)
            ->post(route('companies.documents.store', [$this->company, 'estimate']), [
                'date' => '2026-07-01', 'party_account_id' => $this->customer->id,
                'lines' => [['account_id' => $this->sales->id, 'taxable' => 10000, 'discount_percent' => 20, 'rate' => 18]],
            ])
            ->assertSessionHasNoErrors();
        $estimate = $this->company->businessDocuments()->firstOrFail();
        $this->assertSame('9440.00', $estimate->amount); // 8,000 + 18%

        $this->actingAs($this->company->user)
            ->get(route('companies.gst-invoices.create', [$this->company, 'type' => 'sales', 'from_document' => $estimate->id]))
            ->assertSee('name="lines[0][discount_percent]" value="20"', false)
            ->assertSee('name="lines[0][taxable]" value="10000"', false);

        $this->invoice([['account_id' => $this->sales->id, 'taxable' => 100, 'discount_percent' => 120, 'rate' => 18]])
            ->assertSessionHasErrors('lines.0.discount_percent');
    }
}
