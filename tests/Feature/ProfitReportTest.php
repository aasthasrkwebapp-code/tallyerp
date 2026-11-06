<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pen costs ₹10 (opening 100 @ 10). Sell 10 @ 15 to A, 5 @ 20 to B; A returns 2 @ 15.
 * Pen: qty 13, value 150 + 100 − 30 = 220, cost 130, profit 90.
 * Plus a ₹500 service to B (no cost).
 */
class ProfitReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_item_and_party_profit(): void
    {
        $company = User::factory()->create()->companies()->create([
            'name' => 'Pen Co', 'gstin' => '27AAAAA0000A1Z5', 'financial_year' => '2026-27', 'currency' => 'INR',
        ]);
        $group = fn (string $code) => $company->accountGroups()->where('code', $code)->value('id');
        $sales = $company->accounts()->create(['name' => 'Sales', 'account_group_id' => $group('SALES_ACCOUNTS'), 'account_type' => 'income']);
        $a = $company->accounts()->create(['name' => 'Shop A', 'account_group_id' => $group('SUNDRY_DEBTORS'), 'account_type' => 'assets', 'state_code' => '27']);
        $b = $company->accounts()->create(['name' => 'Shop B', 'account_group_id' => $group('SUNDRY_DEBTORS'), 'account_type' => 'assets', 'state_code' => '27']);
        $pen = $company->stockItems()->create(['name' => 'Pen', 'unit_id' => $company->units()->where('symbol', 'Nos')->value('id'), 'opening_quantity' => 100, 'opening_rate' => 10]);
        $user = $company->user;

        $post = fn (string $type, $party, array $lines) => $this->actingAs($user)->post(route('companies.gst-invoices.store', $company), [
            'type' => $type, 'date' => '2026-06-01', 'party_account_id' => $party->id, 'lines' => $lines,
        ])->assertSessionHasNoErrors();
        $penLine = fn (int $qty, int $rate) => ['account_id' => $sales->id, 'stock_item_id' => $pen->id, 'quantity' => $qty, 'item_rate' => $rate, 'rate' => 0];

        $post('sales', $a, [$penLine(10, 15)]);
        $post('sales', $b, [$penLine(5, 20), ['account_id' => $sales->id, 'taxable' => 500, 'rate' => 18]]);
        $post('credit_note', $a, [$penLine(2, 15)]);

        // Returned pens come back into stock at ₹15, so the average is (1,000 + 30) / 102.
        $avg = round(1030 / 102, 2);

        // Item-wise profit on the Stock Summary.
        $pen = $this->actingAs($user)->get(route('companies.inventory.summary', $company))->assertOk()->viewData('rows')->first();
        $this->assertSame(220.0, $pen->sold_value);
        $this->assertSame(round(13 * $avg, 2), $pen->cost);
        $this->assertSame(round(220 - 13 * $avg, 2), $pen->profit);

        // Party-wise sales and profit on Receivables (services have no cost).
        $parties = $this->actingAs($user)->get(route('companies.outstanding', [$company, 'receivables', 'to' => '2026-06-30']))
            ->assertOk()->assertSee('Sales / profit (year)')
            ->viewData('parties')->keyBy(fn ($r) => $r->party->name);
        $this->assertSame(120.0, $parties['Shop A']->sold_value);       // 150 − 30
        $this->assertSame(600.0, $parties['Shop B']->sold_value);       // 100 + 500
        $this->assertSame(round(600 - 5 * $avg, 2), $parties['Shop B']->profit);
    }
}
