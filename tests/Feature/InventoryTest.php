<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Company;
use App\Models\StockItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Worked example:
 *   Opening: Laptop 10 Nos @ 1,000 (10,000) funded by Capital 10,000 Cr
 *   Purchase 10 @ 1,200 = 12,000 + 18% GST (intra-state)
 *   Sale 15 @ 2,000 = 30,000 + 18% GST (intra-state)
 *   Weighted average cost = 22,000 / 20 = 1,100; closing 5 Nos = 5,500
 *   Gross profit = 30,000 + 5,500 − 12,000 − 10,000 = 13,500
 */
class InventoryTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected StockItem $laptop;

    protected Account $sales;

    protected Account $purchases;

    protected Account $party;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = User::factory()->create()->companies()->create([
            'name' => 'Acme Traders',
            'gstin' => '27AAAAA0000A1Z5',
            'financial_year' => '2026-27',
            'currency' => 'INR',
        ]);

        $this->sales = $this->ledger(['name' => 'Sales'], 'SALES_ACCOUNTS');
        $this->purchases = $this->ledger(['name' => 'Purchases'], 'PURCHASE_ACCOUNTS');
        $this->party = $this->ledger(['name' => 'Pune Stores', 'state_code' => '27'], 'SUNDRY_DEBTORS');
        $this->ledger(['name' => 'Capital', 'opening_balance' => 10000, 'opening_balance_type' => 'cr'], 'CAPITAL_ACCOUNT');

        $this->laptop = $this->company->stockItems()->create([
            'name' => 'Laptop',
            'unit_id' => $this->company->units()->where('symbol', 'Nos')->value('id'),
            'hsn_sac' => '8471',
            'gst_rate' => 18,
            'opening_quantity' => 10,
            'opening_rate' => 1000,
        ]);
    }

    protected function ledger(array $attributes, string $groupCode): Account
    {
        $group = $this->company->accountGroups()->where('code', $groupCode)->firstOrFail();

        return $this->company->accounts()->create($attributes + [
            'account_group_id' => $group->id,
            'account_type' => $group->nature,
        ]);
    }

    protected function invoice(string $type, Account $ledger, float $qty, float $rate, string $date = '2026-07-10')
    {
        return $this->actingAs($this->company->user)
            ->post(route('companies.gst-invoices.store', $this->company), [
                'type' => $type,
                'date' => $date,
                'party_account_id' => $this->party->id,
                'lines' => [[
                    'account_id' => $ledger->id,
                    'stock_item_id' => $this->laptop->id,
                    'quantity' => $qty,
                    'item_rate' => $rate,
                    'rate' => 18,
                ]],
            ]);
    }

    protected function postBoth(): void
    {
        $this->invoice('purchase', $this->purchases, 10, 1200, '2026-06-01')->assertSessionHasNoErrors();
        $this->invoice('sales', $this->sales, 15, 2000, '2026-07-10')->assertSessionHasNoErrors();
    }

    public function test_new_company_gets_default_units(): void
    {
        $this->assertTrue($this->company->units()->where('symbol', 'Kg')->exists());
    }

    public function test_invoice_with_stock_item_computes_taxable_and_moves_stock(): void
    {
        $this->postBoth();

        $sale = $this->company->vouchers()->where('type', 'sales')->firstOrFail();
        $this->assertSame('35400.00', $sale->amount);
        $this->assertSame('30000.00', $sale->gstLines()->value('taxable'));

        $this->assertSame(10.0, (float) $this->laptop->movements()->sum('quantity_in'));
        $this->assertSame(15.0, (float) $this->laptop->movements()->sum('quantity_out'));

        $this->actingAs($this->company->user)
            ->get(route('companies.gst-invoices.show', [$this->company, $sale]))
            ->assertOk()
            ->assertSee('Laptop')
            ->assertSee('15 Nos');
    }

    public function test_stock_summary_uses_weighted_average(): void
    {
        $this->postBoth();

        $response = $this->actingAs($this->company->user)
            ->get(route('companies.inventory.summary', $this->company))
            ->assertOk();

        $row = $response->viewData('rows')->first();
        $this->assertSame(10.0, $row->opening_qty);
        $this->assertSame(10.0, $row->inwards);
        $this->assertSame(15.0, $row->outwards);
        $this->assertSame(5.0, $row->closing_qty);
        $this->assertSame(1100.0, $row->rate);
        $this->assertSame(5500.0, $row->closing_value);
    }

    public function test_stock_summary_period_moves_earlier_movements_into_opening(): void
    {
        $this->postBoth();

        $row = $this->actingAs($this->company->user)
            ->get(route('companies.inventory.summary', [$this->company, 'from' => '2026-07-01']))
            ->viewData('rows')->first();

        $this->assertSame(20.0, $row->opening_qty);
        $this->assertSame(0.0, $row->inwards);
    }

    public function test_profit_and_loss_includes_opening_and_closing_stock(): void
    {
        $this->postBoth();

        $response = $this->actingAs($this->company->user)
            ->get(route('companies.reports.profit-and-loss', $this->company))
            ->assertOk()
            ->assertSee('Opening Stock')
            ->assertSee('Closing Stock');

        $this->assertSame(13500.0, $response->viewData('grossProfit'));
        $this->assertSame(13500.0, $response->viewData('netProfit'));
    }

    public function test_balance_sheet_balances_with_stock(): void
    {
        $this->postBoth();

        $response = $this->actingAs($this->company->user)
            ->get(route('companies.reports.balance-sheet', $this->company))
            ->assertOk();

        $this->assertSame(26740.0, $response->viewData('totalAssets'));
        $this->assertSame(26740.0, $response->viewData('totalLiabilities'));
        $this->assertSame(0.0, $response->viewData('difference'));
    }

    public function test_stock_line_needs_quantity_and_rate(): void
    {
        $this->actingAs($this->company->user)
            ->post(route('companies.gst-invoices.store', $this->company), [
                'type' => 'sales',
                'date' => '2026-07-10',
                'party_account_id' => $this->party->id,
                'lines' => [['account_id' => $this->sales->id, 'stock_item_id' => $this->laptop->id, 'rate' => 18]],
            ])
            ->assertSessionHasErrors(['lines.0.quantity', 'lines.0.item_rate']);
    }

    public function test_stock_item_crud_and_delete_protection(): void
    {
        $unit = $this->company->units()->where('symbol', 'Kg')->value('id');

        $this->actingAs($this->company->user)
            ->post(route('companies.stock-items.store', $this->company), [
                'name' => 'Rice',
                'unit_id' => $unit,
                'gst_rate' => 5,
                'opening_quantity' => '100.5',
                'opening_rate' => '40',
            ])
            ->assertRedirect(route('companies.stock-items.index', $this->company));

        $rice = $this->company->stockItems()->where('name', 'Rice')->firstOrFail();
        $this->assertSame(4020.0, $rice->openingValue());

        $this->actingAs($this->company->user)
            ->get(route('companies.stock-items.index', $this->company))
            ->assertOk()
            ->assertSee('Rice');

        $this->actingAs($this->company->user)
            ->delete(route('companies.stock-items.destroy', [$this->company, $rice]))
            ->assertRedirect();
        $this->assertModelMissing($rice);

        $this->postBoth();
        $this->actingAs($this->company->user)
            ->delete(route('companies.stock-items.destroy', [$this->company, $this->laptop]))
            ->assertSessionHas('error');
        $this->assertModelExists($this->laptop);
    }

    public function test_units_and_groups_setup(): void
    {
        $this->actingAs($this->company->user)
            ->post(route('companies.inventory.units.store', $this->company), ['symbol' => 'Dozen', 'decimals' => 0])
            ->assertSessionHasNoErrors();
        $this->actingAs($this->company->user)
            ->post(route('companies.inventory.groups.store', $this->company), ['name' => 'Electronics'])
            ->assertSessionHasNoErrors();

        $this->actingAs($this->company->user)
            ->get(route('companies.inventory.setup', $this->company))
            ->assertOk()
            ->assertSee('Dozen')
            ->assertSee('Electronics');

        // A unit in use can't be deleted.
        $nos = $this->company->units()->where('symbol', 'Nos')->firstOrFail();
        $this->actingAs($this->company->user)
            ->delete(route('companies.inventory.units.destroy', [$this->company, $nos]))
            ->assertSessionHas('error');
    }

    public function test_cannot_use_another_companys_stock_item(): void
    {
        $other = User::factory()->create()->companies()->create([
            'name' => 'Other', 'financial_year' => '2026-27', 'currency' => 'INR', 'state' => 'Maharashtra',
        ]);
        $foreign = $other->stockItems()->create([
            'name' => 'Foreign', 'unit_id' => $other->units()->value('id'),
        ]);

        $this->actingAs($this->company->user)
            ->post(route('companies.gst-invoices.store', $this->company), [
                'type' => 'sales',
                'date' => '2026-07-10',
                'party_account_id' => $this->party->id,
                'lines' => [['account_id' => $this->sales->id, 'stock_item_id' => $foreign->id, 'quantity' => 1, 'item_rate' => 1, 'rate' => 18]],
            ])
            ->assertSessionHasErrors('lines.0.stock_item_id');

        $this->actingAs($other->user)
            ->get(route('companies.stock-items.edit', [$this->company, $this->laptop]))
            ->assertForbidden();
    }
}
