<?php

namespace Tests\Feature;

use App\Http\Controllers\GodownController;
use App\Models\Company;
use App\Models\StockItem;
use App\Models\User;
use App\Support\CompanyBackup;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Rice: 100 bags opening (Main location) at ₹1,000.
 * Move 30 to Warehouse 2, sell 10 from Warehouse 2.
 * Main 70, Warehouse 2 20; company total 90, value unchanged by the move.
 */
class GodownTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected StockItem $rice;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = User::factory()->create()->companies()->create([
            'name' => 'Grain Co', 'gstin' => '27AAAAA0000A1Z5', 'financial_year' => '2026-27', 'currency' => 'INR',
        ]);
        $this->rice = $this->company->stockItems()->create([
            'name' => 'Rice', 'unit_id' => $this->company->units()->where('symbol', 'Nos')->value('id'), 'opening_quantity' => 100, 'opening_rate' => 1000,
        ]);
    }

    protected function move(array $data)
    {
        return $this->actingAs($this->company->user)->post(route('companies.godowns.transfer', $this->company), $data + [
            'date' => '2026-05-01', 'stock_item_id' => $this->rice->id, 'from_godown_id' => '',
        ]);
    }

    public function test_godowns_transfers_and_invoice_location(): void
    {
        $user = $this->company->user;
        $this->actingAs($user)->post(route('companies.godowns.store', $this->company), ['name' => 'Warehouse 2'])->assertSessionHasNoErrors();
        $wh = $this->company->godowns()->firstOrFail();

        $this->move(['to_godown_id' => $wh->id, 'quantity' => 30])
            ->assertSessionHasNoErrors()->assertSessionHas('success', 'Stock journal #1: moved to Warehouse 2.');

        // Sell 10 from Warehouse 2.
        $group = fn (string $code) => $this->company->accountGroups()->where('code', $code)->value('id');
        $sales = $this->company->accounts()->create(['name' => 'Sales', 'account_group_id' => $group('SALES_ACCOUNTS'), 'account_type' => 'income']);
        $party = $this->company->accounts()->create(['name' => 'Buyer', 'account_group_id' => $group('SUNDRY_DEBTORS'), 'account_type' => 'assets', 'state_code' => '27']);
        $this->actingAs($user)->post(route('companies.gst-invoices.store', $this->company), [
            'type' => 'sales', 'date' => '2026-05-10', 'party_account_id' => $party->id, 'godown_id' => $wh->id,
            'lines' => [['account_id' => $sales->id, 'stock_item_id' => $this->rice->id, 'quantity' => 10, 'item_rate' => 1200, 'rate' => 5]],
        ])->assertSessionHasNoErrors();

        $stock = GodownController::stockByGodown($this->company, CarbonImmutable::parse('2026-06-01'));
        $this->assertSame([0 => 70.0, $wh->id => 20.0], $stock[$this->rice->id]);

        // Company-wide stock ignores the move.
        $row = $this->actingAs($user)->get(route('companies.inventory.summary', $this->company))->viewData('rows')->first();
        $this->assertSame([90.0, 0.0, 10.0], [$row->closing_qty, $row->inwards, $row->outwards]);
        $this->assertSame(90000.0, $row->closing_value);

        // Godowns are managed with units and groups; stock by location shows on Stock Items.
        $this->actingAs($user)->get(route('companies.inventory.setup', $this->company))->assertOk()->assertSee('Warehouse 2')->assertSee('in use');
        $this->actingAs($user)->get(route('companies.stock-items.index', $this->company))
            ->assertOk()->assertSee('Stock by godown')->assertSee('Main location')->assertSee('Stock journal');

        // Can't move more than is there, or to the same place.
        $this->move(['from_godown_id' => $wh->id, 'to_godown_id' => '', 'quantity' => 25, 'date' => '2026-06-01'])->assertSessionHasErrors('quantity');
        $this->move(['to_godown_id' => '', 'quantity' => 1])->assertSessionHasErrors('to_godown_id');

        // A godown that holds stock can't be deleted.
        $this->actingAs($user)->delete(route('companies.godowns.destroy', [$this->company, $wh]))->assertSessionHas('error');

        // Backup keeps godowns and transfers.
        $copy = CompanyBackup::restore(CompanyBackup::export($this->company), $user, 'Copy');
        $copyWh = $copy->godowns()->firstOrFail();
        $copyRice = $copy->stockItems()->firstOrFail();
        $this->assertSame([0 => 70.0, $copyWh->id => 20.0], GodownController::stockByGodown($copy, CarbonImmutable::parse('2026-06-01'))[$copyRice->id]);

        // Deleting the journal puts the stock back.
        $this->actingAs($user)->delete(route('companies.godowns.transfer.destroy', [$this->company, $this->company->stockTransfers()->first()]))->assertSessionHas('success');
        $this->assertSame([0 => 100.0, $wh->id => -10.0], GodownController::stockByGodown($this->company, CarbonImmutable::parse('2026-06-01'))[$this->rice->id]);

        $this->actingAs($user)->delete(route('companies.destroy', $this->company))->assertRedirect();
        $this->assertModelMissing($this->company);
    }
}
