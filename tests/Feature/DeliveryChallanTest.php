<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeliveryChallanTest extends TestCase
{
    use RefreshDatabase;

    public function test_challan_moves_no_stock_until_converted_once(): void
    {
        $company = User::factory()->create()->companies()->create([
            'name' => 'Acme Traders', 'gstin' => '27AAAAA0000A1Z5', 'financial_year' => '2026-27', 'currency' => 'INR',
        ]);
        $group = fn (string $code) => $company->accountGroups()->where('code', $code)->value('id');
        $sales = $company->accounts()->create(['name' => 'Sales', 'account_group_id' => $group('SALES_ACCOUNTS'), 'account_type' => 'income']);
        $customer = $company->accounts()->create(['name' => 'Pune Stores', 'account_group_id' => $group('SUNDRY_DEBTORS'), 'account_type' => 'assets', 'state_code' => '27']);
        $laptop = $company->stockItems()->create(['name' => 'Laptop', 'unit_id' => $company->units()->where('symbol', 'Nos')->value('id'), 'opening_quantity' => 10, 'opening_rate' => 40000]);
        $line = ['account_id' => $sales->id, 'stock_item_id' => $laptop->id, 'quantity' => 3, 'item_rate' => 50000, 'hsn_sac' => '8471', 'rate' => 18];

        $this->actingAs($company->user)
            ->post(route('companies.documents.store', [$company, 'delivery_challan']), [
                'date' => '2026-07-01', 'party_account_id' => $customer->id, 'lines' => [$line],
            ])
            ->assertSessionHasNoErrors();
        $challan = $company->businessDocuments()->firstOrFail();
        $this->assertSame(0, $company->stockMovements()->count());

        $this->actingAs($company->user)
            ->get(route('companies.documents.show', [$company, 'delivery_challan', $challan]))
            ->assertSee('DELIVERY CHALLAN')
            ->assertSee("Receiver's signature", false);

        $this->actingAs($company->user)
            ->post(route('companies.gst-invoices.store', $company), [
                'type' => 'sales', 'date' => '2026-07-03', 'party_account_id' => $customer->id, 'document_id' => $challan->id, 'lines' => [$line],
            ])
            ->assertSessionHasNoErrors();

        // Stock goes out once, with the invoice.
        $this->assertSame(3.0, (float) $company->stockMovements()->sum('quantity_out'));
        $this->assertSame('converted', $challan->fresh()->status);

        // The same challan can't be invoiced twice.
        $this->actingAs($company->user)
            ->post(route('companies.gst-invoices.store', $company), [
                'type' => 'sales', 'date' => '2026-07-04', 'party_account_id' => $customer->id, 'document_id' => $challan->id, 'lines' => [$line],
            ])
            ->assertSessionHasErrors('document_id');
    }
}
