<?php

namespace Tests\Feature;

use App\Http\Controllers\PriceListController;
use App\Models\Company;
use App\Models\User;
use App\Support\CompanyBackup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PriceListTest extends TestCase
{
    use RefreshDatabase;

    public function test_price_list_prices_assigned_party_and_bill_form(): void
    {
        $company = User::factory()->create()->companies()->create([
            'name' => 'Acme Traders', 'gstin' => '27AAAAA0000A1Z5', 'financial_year' => '2026-27', 'currency' => 'INR',
        ]);
        $unit = $company->units()->where('symbol', 'Nos')->value('id');
        $laptop = $company->stockItems()->create(['name' => 'Laptop', 'unit_id' => $unit, 'selling_price' => 52000]);
        $mouse = $company->stockItems()->create(['name' => 'Mouse', 'unit_id' => $unit, 'selling_price' => 500]);
        $as = fn () => $this->actingAs($company->user);

        $as()->post(route('companies.price-lists.store', $company), ['name' => 'Wholesale'])->assertSessionHasNoErrors();
        $list = $company->priceLists()->firstOrFail();

        $as()->put(route('companies.price-lists.update', [$company, $list]), [
            'name' => 'Wholesale', 'prices' => [$laptop->id => 48000, $mouse->id => ''],
        ])->assertSessionHasNoErrors();
        $this->assertSame(['48000.00'], $list->items()->pluck('price')->all());

        // Assign the list to a dealer's ledger.
        $as()->post(route('companies.accounts.store', $company), [
            'name' => 'Dealer One', 'account_group_id' => $company->accountGroups()->where('code', 'SUNDRY_DEBTORS')->value('id'),
            'opening_balance_type' => 'dr', 'is_active' => '1', 'price_list_id' => $list->id,
        ])->assertSessionHasNoErrors();
        $dealer = $company->accounts()->where('name', 'Dealer One')->firstOrFail();

        $this->assertSame([
            'parties' => [$dealer->id => $list->id],
            'prices' => [$list->id => [$laptop->id => 48000.0]],
        ], PriceListController::forForm($company));

        $as()->get(route('companies.gst-invoices.create', [$company, 'type' => 'sales']))
            ->assertSee('listPrice(e.target.value, s.price)', false)
            ->assertSee('"prices":{"'.$list->id.'":{"'.$laptop->id.'":48000', false);

        $as()->get(route('companies.price-lists.index', $company))->assertSee('Wholesale');

        // Backups keep lists, prices and the party link.
        $copy = CompanyBackup::restore(CompanyBackup::export($company), $company->user, 'Copy');
        $copyList = $copy->priceLists()->firstOrFail();
        $this->assertSame(1, $copyList->items()->count());
        $this->assertSame($copyList->id, $copy->accounts()->where('name', 'Dealer One')->value('price_list_id'));

        // Deleting the list sends parties back to normal prices.
        $as()->delete(route('companies.price-lists.destroy', [$company, $list]));
        $this->assertNull($dealer->fresh()->price_list_id);
    }
}
