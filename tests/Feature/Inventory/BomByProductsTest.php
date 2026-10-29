<?php

namespace Tests\Feature\Inventory;

use App\Models\Account;
use App\Models\Godown;
use App\Models\StockItem;
use App\Models\Unit;
use App\Services\Inventory\BillOfMaterials;
use App\Services\Inventory\StockLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class BomByProductsTest extends TestCase
{
    use RefreshDatabase;

    protected $owner;
    protected $company;
    protected Godown $main;
    protected StockItem $cane;
    protected StockItem $sugar;
    protected StockItem $molasses;
    protected StockItem $bagasse;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->owner, $this->company] = $this->createCompanyWithOwner();
        $this->main = Godown::query()->where('is_default', true)->firstOrFail();
        // 5,000 kg cane @ 3 = 15,000; 100 kg bagasse in stock @ 1.
        $this->cane = $this->item('Sugar Cane', '5000', '15000');
        $this->sugar = $this->item('Sugar');
        $this->molasses = $this->item('Molasses');
        $this->bagasse = $this->item('Bagasse', '100', '100');
    }

    protected function item(string $name, string $qty = '0', string $value = '0'): StockItem
    {
        return StockItem::create([
            'name' => $name, 'unit_id' => Unit::where('symbol', 'Kg')->value('id'),
            'sales_account_id' => Account::idFor('sales'), 'purchase_account_id' => Account::idFor('purchase'),
            'opening_quantity' => $qty, 'opening_value' => $value, 'opening_godown_id' => $this->main->id,
        ])->load('unit');
    }

    /** 1,000 kg cane make 100 kg sugar, with 40 kg molasses at 5 a kg and 300 kg bagasse at its cost. */
    protected function bom(): StockItem
    {
        return app(BillOfMaterials::class)->save($this->sugar, ['bom_quantity' => '100', 'components' => [
            ['component_id' => $this->cane->id, 'quantity' => '1000'],
            ['component_id' => $this->molasses->id, 'kind' => 'by_product', 'quantity' => '40', 'rate' => '5'],
            ['component_id' => $this->bagasse->id, 'kind' => 'scrap', 'quantity' => '300', 'rate' => ''],
        ]]);
    }

    protected function position(StockItem $item): object
    {
        return app(StockLedger::class)->position(null, collect([$item->fresh('unit')]))[$item->id];
    }

    public function test_by_products_and_scrap_come_out_and_reduce_the_cost(): void
    {
        $sugar = $this->bom();
        $this->assertSame(['component', 'by_product', 'scrap'], $sugar->bomComponents->pluck('kind')->all());
        $this->assertSame([null, '5.00', null], $sugar->bomComponents->pluck('rate')->all());

        $boms = app(BillOfMaterials::class);
        $this->assertCount(1, $boms->requirements($sugar, 250000, '2026-05-01')); // components only
        $outputs = $boms->outputs($sugar, 250000, '2026-05-01');
        $this->assertSame([100000, 750000], array_column($outputs, 'quantity')); // 40 and 300 kg per 100 → 100 and 750 kg
        $this->assertSame([500, 100], array_column($outputs, 'rate'));

        // 250 kg sugar: cane 2,500 kg @ 3 = 7,500; molasses 100 kg @ 5 = 500; bagasse 750 kg @ 1 = 750.
        $journal = $boms->manufacture($sugar->fresh(), ['date' => '2026-05-01', 'quantity' => '250', 'godown_id' => $this->main->id, 'other_cost' => '250'], $this->owner);
        $this->assertSame('production', $journal->purpose);
        $this->assertStringContainsString('by-products and scrap 100 Kg Molasses, 750 Kg Bagasse valued at 1,250.00', $journal->narration);
        $this->assertSame(250000, $this->position($this->sugar)->quantity);
        $this->assertSame(750000 + 25000 - 125000, $this->position($this->sugar)->value);   // 6,500
        $this->assertSame([100000, 50000], [$this->position($this->molasses)->quantity, $this->position($this->molasses)->value]);
        $this->assertSame([850000, 85000], [$this->position($this->bagasse)->quantity, $this->position($this->bagasse)->value]);
        // Value in equals the cane used plus other costs: 6,500 + 500 + 750 = 7,750.
        $this->assertSame(750000 + 25000, $this->position($this->sugar)->value + $this->position($this->molasses)->value + $this->position($this->bagasse)->value - 10000);

        // Actual quantities can differ: no molasses this time, 500 kg bagasse.
        $again = $boms->manufacture($sugar->fresh(), ['date' => '2026-05-02', 'quantity' => '100', 'godown_id' => $this->main->id, 'outputs' => [
            $sugar->bomComponents[1]->id => '0', $sugar->bomComponents[2]->id => '500',
        ]], $this->owner);
        $this->assertSame(['Sugar', 'Bagasse'], $again->lines->where('direction', 'in')->map(fn ($l) => $l->item->name)->values()->all());
    }

    public function test_validation(): void
    {
        $boms = app(BillOfMaterials::class);
        $errors = function (callable $fn) {
            try {
                $fn();
                $this->fail('Accepted');
            } catch (ValidationException $e) {
                return array_keys($e->errors());
            }
        };
        // A bill with only by-products, and a bad rate.
        $this->assertSame(['components'], $errors(fn () => $boms->save($this->sugar, ['bom_quantity' => '100', 'components' => [['component_id' => $this->molasses->id, 'kind' => 'by_product', 'quantity' => '40']]])));
        $this->assertSame(['components.1.rate'], $errors(fn () => $boms->save($this->sugar, ['bom_quantity' => '100', 'components' => [
            ['component_id' => $this->cane->id, 'quantity' => '1000'], ['component_id' => $this->molasses->id, 'kind' => 'scrap', 'quantity' => '40', 'rate' => 'abc']]])));

        // By-products worth more than the cost are refused.
        $sugar = $boms->save($this->sugar, ['bom_quantity' => '100', 'components' => [
            ['component_id' => $this->cane->id, 'quantity' => '1000'], ['component_id' => $this->molasses->id, 'kind' => 'by_product', 'quantity' => '40', 'rate' => '100']]]);
        $this->assertSame(['outputs'], $errors(fn () => $boms->manufacture($sugar, ['date' => '2026-05-01', 'quantity' => '100', 'godown_id' => $this->main->id], $this->owner)));
        $line = $sugar->bomComponents[1]->id;
        $this->assertSame(["outputs.$line"], $errors(fn () => $boms->manufacture($sugar->fresh(), ['date' => '2026-05-01', 'quantity' => '100', 'godown_id' => $this->main->id, 'outputs' => [$line => '1.2345']], $this->owner)));
    }

    public function test_screens(): void
    {
        $this->actingAs($this->owner)->put(route('stock-items.bom.update', $this->sugar), ['bom_quantity' => '100', 'components' => [
            ['component_id' => $this->cane->id, 'kind' => 'component', 'quantity' => '1000'],
            ['component_id' => $this->molasses->id, 'kind' => 'by_product', 'quantity' => '40', 'rate' => '5'],
        ]])->assertRedirect(route('stock-items.show', $this->sugar));
        $this->get(route('stock-items.bom.edit', $this->sugar))->assertOk()->assertSee('By-product')->assertSee('5.00');
        $this->get(route('stock-items.show', $this->sugar))->assertOk()->assertSee('By-product');
        $this->get(route('stock-items.show', $this->molasses))->assertOk()->assertSee('Co-product, by-product or scrap of:');
        $this->get(route('stock-items.show', $this->cane))->assertOk()->assertSee('Used in:');
        $this->get(route('stock-items.manufacture', [$this->sugar, 'quantity' => '100', 'date' => '2026-05-01']))->assertOk()
            ->assertSee('By-products and scrap obtained')->assertSee('Less by-products and scrap')->assertSee('2,800.00'); // 3,000 − 200
        $line = $this->sugar->bomComponents()->where('kind', 'by_product')->value('id');
        $this->post(route('stock-items.manufacture.store', $this->sugar), ['date' => '2026-05-01', 'quantity' => '100', 'godown_id' => $this->main->id, 'outputs' => [$line => '30']])
            ->assertRedirect();
        $this->assertSame(30000, $this->position($this->molasses)->quantity);
        $this->assertSame(300000 - 15000, $this->position($this->sugar)->value);
    }
}
