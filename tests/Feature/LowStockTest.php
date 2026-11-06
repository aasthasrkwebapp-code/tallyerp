<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LowStockTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = User::factory()->create()->companies()->create([
            'name' => 'Shop', 'financial_year' => now()->month >= 4 ? now()->year.'-'.sprintf('%02d', (now()->year + 1) % 100) : (now()->year - 1).'-'.sprintf('%02d', now()->year % 100),
            'currency' => 'INR',
        ]);
        $unit = $this->company->units()->where('symbol', 'Nos')->value('id');
        $this->company->stockItems()->create(['name' => 'Soap', 'unit_id' => $unit, 'opening_quantity' => 4, 'opening_rate' => 25, 'reorder_level' => 10]);
        $this->company->stockItems()->create(['name' => 'Shampoo', 'unit_id' => $unit, 'opening_quantity' => 50, 'opening_rate' => 100, 'reorder_level' => 10]);
        $this->company->stockItems()->create(['name' => 'Comb', 'unit_id' => $unit, 'opening_quantity' => 0, 'opening_rate' => 10]);
    }

    public function test_low_stock_report_and_dashboard_alert(): void
    {
        $user = $this->company->user;

        // Stock Summary, filtered to items at or below their reorder level.
        $rows = $this->actingAs($user)->get(route('companies.inventory.summary', [$this->company, 'filter' => 'low']))
            ->assertOk()->assertSee('Soap')->assertDontSee('Shampoo')
            ->viewData('rows');
        $this->assertCount(1, $rows);
        $this->assertTrue($rows[0]->low);

        // Unfiltered, every item is listed and Soap is flagged.
        $all = $this->actingAs($user)->get(route('companies.inventory.summary', $this->company))->viewData('rows')->keyBy(fn ($r) => $r->item->name);
        $this->assertCount(3, $all);
        $this->assertSame([true, false], [$all['Soap']->low, $all['Shampoo']->low]);

        // The dashboard helper still reports the shortfall.
        $low = \App\Http\Controllers\InventoryController::lowStockRows($this->company, \App\Http\Controllers\InventoryController::stockDate($this->company));
        $this->assertSame([6.0, 25.0], [$low[0]->short, $low[0]->rate]);

        $this->actingAs($user)->get(route('companies.show', $this->company))
            ->assertOk()->assertSee('1 item at or below reorder level: Soap');
    }

    public function test_reorder_level_is_saved_from_the_form(): void
    {
        $item = $this->company->stockItems()->where('name', 'Comb')->firstOrFail();

        $this->actingAs($this->company->user)
            ->put(route('companies.stock-items.update', [$this->company, $item]), [
                'name' => 'Comb', 'unit_id' => $item->unit_id, 'reorder_level' => 5,
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('5.000', $item->fresh()->reorder_level);
        $this->actingAs($this->company->user)->get(route('companies.inventory.summary', [$this->company, 'filter' => 'low']))->assertSee('Comb');
    }
}
