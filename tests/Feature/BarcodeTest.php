<?php

namespace Tests\Feature;

use App\Http\Controllers\StockItemController;
use App\Models\Company;
use App\Models\User;
use App\Support\Barcode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BarcodeTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = User::factory()->create()->companies()->create([
            'name' => 'Acme Traders', 'gstin' => '27AAAAA0000A1Z5', 'financial_year' => '2026-27', 'currency' => 'INR',
        ]);
    }

    protected function item(array $data)
    {
        return $this->actingAs($this->company->user)
            ->post(route('companies.stock-items.store', $this->company), $data + [
                'unit_id' => $this->company->units()->where('symbol', 'Nos')->value('id'),
            ]);
    }

    public function test_code128_structure(): void
    {
        $modules = Barcode::modules('AB1');

        // Start B, 3 characters, checksum, stop: 5 × 11 modules + 13 for stop.
        $this->assertSame(5 * 11 + 13, array_sum($modules));
        $this->assertSame([2, 1, 1, 2, 1, 4], array_slice($modules, 0, 6)); // Start B
        $this->assertSame([2, 3, 3, 1, 1, 1, 2], array_slice($modules, -7)); // Stop

        // Checksum: (104 + 1·33 + 2·34 + 3·17) mod 103 = 50 → pattern 231131.
        $this->assertSame([2, 3, 1, 1, 3, 1], array_slice($modules, -13, 6));

        $this->assertTrue(Barcode::encodable('8901234567890'));
        $this->assertFalse(Barcode::encodable('नमस्ते'));
        $this->assertStringContainsString('<svg', Barcode::svg('8901234567890'));
    }

    public function test_barcode_and_selling_price_saved_and_unique(): void
    {
        $this->item(['name' => 'Laptop', 'barcode' => '8901234567890', 'selling_price' => 52000])->assertSessionHasNoErrors();

        $laptop = $this->company->stockItems()->firstOrFail();
        $this->assertSame(['8901234567890', '52000.00'], [$laptop->barcode, $laptop->selling_price]);

        $this->item(['name' => 'Mouse', 'barcode' => '8901234567890'])->assertSessionHasErrors('barcode');

        $this->assertMatchesRegularExpression('/^2\d{12}$/', StockItemController::suggestBarcode($this->company));
    }

    public function test_labels_page_prints_requested_copies(): void
    {
        $this->item(['name' => 'Laptop', 'barcode' => '8901234567890', 'selling_price' => 52000]);
        $laptop = $this->company->stockItems()->firstOrFail();

        $response = $this->actingAs($this->company->user)
            ->get(route('companies.stock-items.labels', [$this->company, 'items' => [$laptop->id => 3]]))
            ->assertOk()
            ->assertSee('Print 3 labels')
            ->assertSee('₹ 52,000.00');

        $this->assertSame(3, substr_count($response->getContent(), 'aria-label="Barcode 8901234567890"'));
    }

    public function test_invoice_and_document_forms_offer_scanning_with_selling_price(): void
    {
        $this->item(['name' => 'Laptop', 'barcode' => '8901234567890', 'selling_price' => 52000, 'opening_rate' => 40000]);
        $laptop = $this->company->stockItems()->firstOrFail();

        $this->actingAs($this->company->user)
            ->get(route('companies.gst-invoices.create', [$this->company, 'type' => 'sales']))
            ->assertSee('Scan barcode')
            ->assertSee('"8901234567890":'.$laptop->id, false)
            ->assertSee('"price":52000', false);

        // Purchases are priced at cost.
        $this->actingAs($this->company->user)
            ->get(route('companies.gst-invoices.create', [$this->company, 'type' => 'purchase']))
            ->assertSee('"price":40000', false);

        $this->actingAs($this->company->user)
            ->get(route('companies.documents.create', [$this->company, 'estimate']))
            ->assertSee('Scan barcode');
    }
}
