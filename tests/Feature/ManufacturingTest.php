<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\StockItem;
use App\Models\User;
use App\Support\CompanyBackup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 1 Gift Box = 2 Chocolates (₹20 each) + 1 Box (₹10).
 * Making 10 with ₹50 labour: 20 chocolates (₹400) + 10 boxes (₹100) + ₹50 = ₹550 → ₹55 a box.
 */
class ManufacturingTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected StockItem $chocolate;

    protected StockItem $box;

    protected StockItem $giftBox;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = User::factory()->create()->companies()->create([
            'name' => 'Sweet Co', 'financial_year' => '2026-27', 'currency' => 'INR',
        ]);
        $unit = $this->company->units()->where('symbol', 'Nos')->value('id');
        $this->chocolate = $this->company->stockItems()->create(['name' => 'Chocolate', 'unit_id' => $unit, 'opening_quantity' => 100, 'opening_rate' => 20]);
        $this->box = $this->company->stockItems()->create(['name' => 'Box', 'unit_id' => $unit, 'opening_quantity' => 50, 'opening_rate' => 10]);
        $this->giftBox = $this->company->stockItems()->create(['name' => 'Gift Box', 'unit_id' => $unit]);

        $this->actingAs($this->company->user)
            ->post(route('companies.manufacturing.bom.store', $this->company), [
                'name' => 'Gift Box recipe', 'stock_item_id' => $this->giftBox->id, 'output_quantity' => 1,
                'components' => [
                    ['stock_item_id' => $this->chocolate->id, 'quantity' => 2],
                    ['stock_item_id' => $this->box->id, 'quantity' => 1],
                ],
            ])
            ->assertSessionHasNoErrors();
    }

    protected function produce(float $qty, float $other = 0)
    {
        return $this->actingAs($this->company->user)
            ->post(route('companies.manufacturing.production.store', $this->company), [
                'bom_id' => $this->company->boms()->value('id'), 'date' => '2026-07-01', 'quantity' => $qty, 'other_cost' => $other,
            ]);
    }

    protected function stock(): \Illuminate\Support\Collection
    {
        return $this->actingAs($this->company->user)
            ->get(route('companies.inventory.summary', $this->company))
            ->viewData('rows')->keyBy(fn ($r) => $r->item->name);
    }

    public function test_production_moves_stock_at_cost(): void
    {
        $this->produce(10, 50)->assertSessionHasNoErrors()->assertSessionHas('success', 'Production #1 saved: 10 Gift Box at ₹ 550.00.');

        $production = $this->company->productions()->firstOrFail();
        $this->assertSame(['500.00', '50.00', 550.0], [$production->material_cost, $production->other_cost, $production->totalCost()]);

        $stock = $this->stock();
        $this->assertSame(80.0, $stock['Chocolate']->closing_qty);
        $this->assertSame(40.0, $stock['Box']->closing_qty);
        $this->assertSame(10.0, $stock['Gift Box']->closing_qty);
        $this->assertSame(55.0, $stock['Gift Box']->rate);
        $this->assertSame(550.0, $stock['Gift Box']->closing_value);

        $this->actingAs($this->company->user)
            ->get(route('companies.manufacturing.index', $this->company))
            ->assertOk()
            ->assertSee('2 Nos Chocolate, 1 Nos Box')
            ->assertSee('55.00');
    }

    public function test_not_enough_raw_material_is_refused(): void
    {
        // 60 gift boxes need 120 chocolates; only 100 in stock.
        $this->produce(60)->assertSessionHasErrors(['quantity' => 'Not enough Chocolate: need 120 Nos, have 100.']);
        $this->assertSame(0, $this->company->productions()->count());
    }

    public function test_deleting_production_restores_stock(): void
    {
        $this->produce(10);
        $production = $this->company->productions()->firstOrFail();

        $this->actingAs($this->company->user)->delete(route('companies.manufacturing.production.destroy', [$this->company, $production]));

        $this->assertSame(100.0, $this->stock()['Chocolate']->closing_qty);
        $this->assertSame(0, $this->company->stockMovements()->count());
    }

    public function test_bom_rules_and_protection(): void
    {
        $this->actingAs($this->company->user)
            ->post(route('companies.manufacturing.bom.store', $this->company), [
                'name' => 'Loop', 'stock_item_id' => $this->giftBox->id, 'output_quantity' => 1,
                'components' => [['stock_item_id' => $this->giftBox->id, 'quantity' => 1]],
            ])
            ->assertSessionHasErrors('components.0.stock_item_id');

        $this->produce(1);
        $bom = $this->company->boms()->firstOrFail();
        $this->actingAs($this->company->user)
            ->delete(route('companies.manufacturing.bom.destroy', [$this->company, $bom]))
            ->assertSessionHas('error');
        $this->assertModelExists($bom);
    }

    public function test_backup_and_company_deletion_include_manufacturing(): void
    {
        $this->produce(10, 50);

        $copy = CompanyBackup::restore(CompanyBackup::export($this->company), $this->company->user, 'Copy');
        $this->assertSame(1, $copy->productions()->count());
        $this->assertSame(2, $copy->boms()->firstOrFail()->components()->count());

        $this->actingAs($this->company->user)->delete(route('companies.destroy', $this->company))->assertRedirect();
        $this->assertModelMissing($this->company);
    }
}
