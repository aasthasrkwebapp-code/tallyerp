<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Company;
use App\Models\StockItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class BatchTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected Account $sales;

    protected Account $purchases;

    protected Account $party;

    protected StockItem $syrup;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-07-20');

        $this->company = User::factory()->create()->companies()->create([
            'name' => 'City Pharma', 'gstin' => '27AAAAA0000A1Z5', 'financial_year' => '2026-27', 'currency' => 'INR',
        ]);
        $group = fn (string $code) => $this->company->accountGroups()->where('code', $code)->value('id');
        $this->sales = $this->company->accounts()->create(['name' => 'Sales', 'account_group_id' => $group('SALES_ACCOUNTS'), 'account_type' => 'income']);
        $this->purchases = $this->company->accounts()->create(['name' => 'Purchases', 'account_group_id' => $group('PURCHASE_ACCOUNTS'), 'account_type' => 'expenses']);
        $this->party = $this->company->accounts()->create(['name' => 'Distributor', 'account_group_id' => $group('SUNDRY_CREDITORS'), 'account_type' => 'liabilities', 'state_code' => '27']);
        $this->syrup = $this->company->stockItems()->create([
            'name' => 'Cough Syrup', 'unit_id' => $this->company->units()->where('symbol', 'Nos')->value('id'), 'track_batches' => true,
        ]);

        // Two batches bought: B1 expires soon, B2 later.
        $this->bill('purchase', $this->purchases, 'B1', 10, '2026-07-01', '2026-08-10')->assertSessionHasNoErrors();
        $this->bill('purchase', $this->purchases, 'B2', 20, '2026-07-02', '2027-06-30')->assertSessionHasNoErrors();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    protected function bill(string $type, Account $ledger, ?string $batch, float $qty, string $date, ?string $expiry = null)
    {
        return $this->actingAs($this->company->user)
            ->post(route('companies.gst-invoices.store', $this->company), [
                'type' => $type, 'date' => $date, 'party_account_id' => $this->party->id,
                'lines' => [[
                    'account_id' => $ledger->id, 'stock_item_id' => $this->syrup->id, 'quantity' => $qty, 'item_rate' => 100,
                    'hsn_sac' => '3004', 'rate' => 12, 'batch_no' => $batch, 'expiry_date' => $expiry,
                ]],
            ]);
    }

    public function test_purchases_create_batches_with_expiry(): void
    {
        $batches = $this->syrup->batches()->orderBy('batch_no')->get();

        $this->assertSame(['B1', 'B2'], $batches->pluck('batch_no')->all());
        $this->assertSame('2026-08-10', $batches[0]->expiry_date->toDateString());
        $this->assertSame([10.0, 20.0], [$batches[0]->balance(), $batches[1]->balance()]);
    }

    public function test_sale_takes_stock_from_the_chosen_batch_and_prints_it(): void
    {
        $this->bill('sales', $this->sales, 'B2', 5, '2026-07-15')->assertSessionHasNoErrors();

        $b2 = $this->syrup->batches()->where('batch_no', 'B2')->firstOrFail();
        $this->assertSame(15.0, $b2->balance());

        $sale = $this->company->vouchers()->where('type', 'sales')->firstOrFail();
        $this->assertSame('2027-06-30', $sale->gstLines()->first()->expiry_date->toDateString());

        $this->actingAs($this->company->user)
            ->get(route('companies.gst-invoices.show', [$this->company, $sale]))
            ->assertSee('Batch B2 · exp 06/2027');
    }

    public function test_batch_is_required_must_exist_and_have_enough(): void
    {
        $this->bill('sales', $this->sales, null, 1, '2026-07-15')->assertSessionHasErrors('lines.0.batch_no');
        $this->bill('sales', $this->sales, 'NOPE', 1, '2026-07-15')->assertSessionHasErrors('lines.0.batch_no');
        $this->bill('sales', $this->sales, 'B1', 11, '2026-07-15')
            ->assertSessionHasErrors(['lines.0.batch_no' => 'Batch B1 has only 10 left.']);

        $this->assertSame(0, $this->company->vouchers()->where('type', 'sales')->count());
    }

    public function test_batch_report_flags_expiring_batches(): void
    {
        $response = $this->actingAs($this->company->user)
            ->get(route('companies.inventory.batches', [$this->company, 'show' => 'expiring']))
            ->assertOk()
            ->assertSee('Expires in 21 days');

        $this->assertSame(['B1'], $response->viewData('rows')->map(fn ($r) => $r->batch->batch_no)->all());

        $all = $this->actingAs($this->company->user)->get(route('companies.inventory.batches', $this->company))->viewData('rows');
        $this->assertSame(['B1', 'B2'], $all->map(fn ($r) => $r->batch->batch_no)->all());
    }

    public function test_invoice_form_suggests_batches(): void
    {
        $this->actingAs($this->company->user)
            ->get(route('companies.gst-invoices.create', [$this->company, 'type' => 'sales']))
            ->assertSee('<datalist id="batches-'.$this->syrup->id.'">', false)
            ->assertSee('exp 10-08-2026 · 10 left')
            ->assertDontSee('aria-label="Expiry date"', false);

        $this->actingAs($this->company->user)
            ->get(route('companies.gst-invoices.create', [$this->company, 'type' => 'purchase']))
            ->assertSee('aria-label="Expiry date"', false);
    }

    public function test_items_without_batch_tracking_are_unaffected(): void
    {
        $this->syrup->update(['track_batches' => false]);

        $this->bill('sales', $this->sales, null, 1, '2026-07-15')->assertSessionHasNoErrors();
    }
}
