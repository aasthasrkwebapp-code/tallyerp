<?php

namespace Tests\Feature\Inventory;

use App\Models\Account;
use App\Models\BomComponent;
use App\Models\Godown;
use App\Models\StockItem;
use App\Models\StockJournal;
use App\Models\Unit;
use App\Services\Inventory\BillOfMaterials;
use App\Services\Inventory\StockLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CoProductTest extends TestCase
{
    use RefreshDatabase;

    protected $owner;
    protected $company;
    protected Godown $main;
    protected StockItem $milk;
    protected StockItem $butter;
    protected StockItem $buttermilk;
    protected StockItem $waste;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->owner, $this->company] = $this->createCompanyWithOwner();
        $this->main = Godown::query()->where('is_default', true)->firstOrFail();
        // 1,000 kg milk @ 40 = 40,000.
        $this->milk = $this->item('Milk', '1000', '40000');
        $this->butter = $this->item('Butter');
        $this->buttermilk = $this->item('Buttermilk');
        $this->waste = $this->item('Waste');
    }

    protected function item(string $name, string $qty = '0', string $value = '0'): StockItem
    {
        return StockItem::create([
            'name' => $name, 'unit_id' => Unit::where('symbol', 'Kg')->value('id'),
            'sales_account_id' => Account::idFor('sales'), 'purchase_account_id' => Account::idFor('purchase'),
            'opening_quantity' => $qty, 'opening_value' => $value, 'opening_godown_id' => $this->main->id,
        ])->load('unit');
    }

    /** 100 kg milk make 10 kg butter and 80 kg buttermilk (30% of the cost), with 5 kg waste at 2 a kg. */
    protected function bom(string $share = '30'): StockItem
    {
        return app(BillOfMaterials::class)->save($this->butter, ['bom_quantity' => '10', 'components' => [
            ['component_id' => $this->milk->id, 'quantity' => '100'],
            ['component_id' => $this->buttermilk->id, 'kind' => 'co_product', 'quantity' => '80', 'cost_share' => $share, 'rate' => '99'],
            ['component_id' => $this->waste->id, 'kind' => 'scrap', 'quantity' => '5', 'rate' => '2'],
        ]]);
    }

    protected function value(StockItem $item): int
    {
        return app(StockLedger::class)->position(null, collect([$item->fresh('unit')]))[$item->id]->value;
    }

    protected function errors(callable $fn): array
    {
        try {
            $fn();
        } catch (ValidationException $e) {
            return $e->errors();
        }
        $this->fail('Not refused');
    }

    public function test_co_products_share_the_cost(): void
    {
        $butter = $this->bom();
        $line = $butter->bomComponents->firstWhere('kind', 'co_product');
        $this->assertSame(['30.00', null], [$line->cost_share, $line->rate]); // the rate is not kept for a co-product

        // Milk 4,000 + other costs 1,000 − waste 10 = 4,990; buttermilk takes 30% = 1,497
        // (18.71 a kg for 80 kg = 1,496.80) and butter the rest, 3,493.20.
        $journal = app(BillOfMaterials::class)->manufacture($butter->fresh(), ['date' => '2026-05-01', 'quantity' => '10', 'godown_id' => $this->main->id, 'other_cost' => '1000'], $this->owner);
        $this->assertSame([349320, 149680, 1000], [$this->value($this->butter), $this->value($this->buttermilk), $this->value($this->waste)]);
        $this->assertSame([80000, '18.71'], [app(StockLedger::class)->position(null, collect([$this->buttermilk->fresh('unit')]))[$this->buttermilk->id]->quantity, $journal->lines->firstWhere('stock_item_id', $this->buttermilk->id)->rate]);
        // The journal's value in equals the milk used plus other costs.
        $this->assertSame('5000.00', number_format($journal->lines->where('direction', 'in')->sum(fn ($l) => (float) $l->value), 2, '.', ''));
        $this->assertStringContainsString('co-products 80 Kg Buttermilk (30% of cost)', $journal->narration);

        // A co-product not obtained leaves its share to the main item.
        app(BillOfMaterials::class)->manufacture($butter->fresh(), ['date' => '2026-05-02', 'quantity' => '10', 'godown_id' => $this->main->id,
            'outputs' => [$line->id => '0']], $this->owner);
        $this->assertSame(349320 + 399000, $this->value($this->butter));
        $this->assertSame(149680, $this->value($this->buttermilk));

        // Actual quantities change the rate, not the share.
        app(BillOfMaterials::class)->manufacture($butter->fresh(), ['date' => '2026-05-03', 'quantity' => '10', 'godown_id' => $this->main->id,
            'outputs' => [$line->id => '60']], $this->owner);
        $last = StockJournal::query()->latest('id')->first()->lines->firstWhere('stock_item_id', $this->buttermilk->id);
        $this->assertSame(['60.000', '19.95'], [$last->quantity, $last->rate]); // 30% of 3,990 = 1,197 over 60 kg
    }

    public function test_cost_share_rules(): void
    {
        $boms = app(BillOfMaterials::class);
        $save = fn (array $co) => $boms->save($this->butter, ['bom_quantity' => '10', 'components' => [
            ['component_id' => $this->milk->id, 'quantity' => '100'],
            ...$co,
        ]]);
        $this->assertSame(['components.1.cost_share'], array_keys($this->errors(fn () => $save([['component_id' => $this->buttermilk->id, 'kind' => 'co_product', 'quantity' => '80']]))));
        $this->assertSame(['components.1.cost_share'], array_keys($this->errors(fn () => $save([['component_id' => $this->buttermilk->id, 'kind' => 'co_product', 'quantity' => '80', 'cost_share' => '100']]))));
        $this->assertSame(['components.1.cost_share'], array_keys($this->errors(fn () => $save([['component_id' => $this->buttermilk->id, 'kind' => 'co_product', 'quantity' => '80', 'cost_share' => '0']]))));
        $errors = $this->errors(fn () => $save([
            ['component_id' => $this->buttermilk->id, 'kind' => 'co_product', 'quantity' => '80', 'cost_share' => '60'],
            ['component_id' => $this->waste->id, 'kind' => 'co_product', 'quantity' => '5', 'cost_share' => '40%'],
        ]));
        $this->assertStringContainsString('add up to 100%', $errors['components'][0]);
        $this->assertSame(0, BomComponent::count());

        $this->bom('12.5');
        $this->assertSame('12.50', BomComponent::where('kind', 'co_product')->value('cost_share'));
    }

    public function test_screens(): void
    {
        $this->actingAs($this->owner)->put(route('stock-items.bom.update', $this->butter), ['bom_quantity' => '10', 'components' => [
            ['component_id' => $this->milk->id, 'kind' => 'component', 'quantity' => '100'],
            ['component_id' => $this->buttermilk->id, 'kind' => 'co_product', 'quantity' => '80', 'cost_share' => '30'],
        ]])->assertRedirect(route('stock-items.show', $this->butter));
        $this->get(route('stock-items.bom.edit', $this->butter))->assertOk()->assertSee('Co-product')->assertSee('value="30"', false);
        $this->get(route('stock-items.show', $this->butter))->assertOk()->assertSee('30% of cost');
        $this->get(route('stock-items.show', $this->buttermilk))->assertOk()->assertSee('Co-product, by-product or scrap of:');
        // Milk 4,000: buttermilk 1,200 (15.00 a kg), butter 2,800.
        $this->get(route('stock-items.manufacture', $this->butter))->assertOk()
            ->assertSee("Less co-products' share", false)->assertSee('1,200.00')->assertSee('2,800.00')->assertSee('15.00');
        $this->post(route('stock-items.manufacture.store', $this->butter), ['date' => '2026-05-01', 'quantity' => '10', 'godown_id' => $this->main->id])->assertSessionHas('success');
        $this->assertSame([280000, 120000], [$this->value($this->butter), $this->value($this->buttermilk)]);
    }
}
