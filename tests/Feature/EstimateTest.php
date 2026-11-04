<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\BusinessDocument;
use App\Models\Company;
use App\Models\StockItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EstimateTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected Account $sales;

    protected Account $customer;

    protected StockItem $laptop;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = User::factory()->create()->companies()->create([
            'name' => 'Acme Traders', 'gstin' => '27AAAAA0000A1Z5', 'financial_year' => '2026-27', 'currency' => 'INR',
        ]);
        $group = fn (string $code) => $this->company->accountGroups()->where('code', $code)->value('id');
        $this->sales = $this->company->accounts()->create(['name' => 'Sales', 'account_group_id' => $group('SALES_ACCOUNTS'), 'account_type' => 'income']);
        $this->customer = $this->company->accounts()->create(['name' => 'Pune Stores', 'account_group_id' => $group('SUNDRY_DEBTORS'), 'account_type' => 'assets', 'state_code' => '27']);
        $this->laptop = $this->company->stockItems()->create([
            'name' => 'Laptop', 'unit_id' => $this->company->units()->where('symbol', 'Nos')->value('id'), 'opening_quantity' => 10, 'opening_rate' => 40000,
        ]);
    }

    protected function createEstimate(array $overrides = [])
    {
        return $this->actingAs($this->company->user)
            ->post(route('companies.documents.store', [$this->company, 'estimate']), $overrides + [
                'date' => '2026-07-01', 'valid_until' => '2026-07-15', 'party_account_id' => $this->customer->id,
                'notes' => 'Prices valid for 15 days.',
                'lines' => [
                    ['account_id' => $this->sales->id, 'stock_item_id' => $this->laptop->id, 'quantity' => 2, 'item_rate' => 50000, 'hsn_sac' => '8471', 'rate' => 18],
                    ['account_id' => $this->sales->id, 'taxable' => 2000, 'hsn_sac' => '998314', 'rate' => 18, 'description' => 'Setup'],
                ],
            ]);
    }

    public function test_estimate_is_saved_without_touching_books_or_stock(): void
    {
        $this->createEstimate()->assertSessionHasNoErrors();

        $estimate = $this->company->businessDocuments()->firstOrFail();
        $this->assertSame(['estimate', 1, 'open', '120360.00'], [$estimate->type, $estimate->number, $estimate->status, $estimate->amount]);
        $this->assertSame(2, $estimate->lines()->count());
        $this->assertSame(0, $this->company->vouchers()->count());
        $this->assertSame(0, $this->company->stockMovements()->count());

        $this->actingAs($this->company->user)
            ->get(route('companies.documents.show', [$this->company, 'estimate', $estimate]))
            ->assertOk()
            ->assertSee('ESTIMATE')
            ->assertSee('1,20,360.00')
            ->assertSee('Convert to Sales Invoice');
    }

    public function test_convert_prefills_invoice_and_marks_estimate_converted(): void
    {
        $this->createEstimate();
        $estimate = $this->company->businessDocuments()->firstOrFail();

        $this->actingAs($this->company->user)
            ->post(route('companies.documents.convert', [$this->company, 'estimate', $estimate]))
            ->assertRedirect(route('companies.gst-invoices.create', [$this->company, 'type' => 'sales', 'from_document' => $estimate->id]));

        $this->actingAs($this->company->user)
            ->get(route('companies.gst-invoices.create', [$this->company, 'type' => 'sales', 'from_document' => $estimate->id]))
            ->assertOk()
            ->assertSee('Converting Estimate 1/2026-27')
            ->assertSee('name="document_id" value="'.$estimate->id.'"', false)
            ->assertSee('value="50000"', false);

        // Saving the invoice (as the pre-filled form would submit it).
        $this->actingAs($this->company->user)
            ->post(route('companies.gst-invoices.store', $this->company), [
                'type' => 'sales', 'date' => '2026-07-05', 'party_account_id' => $this->customer->id, 'document_id' => $estimate->id,
                'lines' => [['account_id' => $this->sales->id, 'stock_item_id' => $this->laptop->id, 'quantity' => 2, 'item_rate' => 50000, 'hsn_sac' => '8471', 'rate' => 18]],
            ])
            ->assertSessionHasNoErrors();

        $estimate->refresh();
        $invoice = $this->company->vouchers()->firstOrFail();
        $this->assertSame(['converted', $invoice->id], [$estimate->status, $estimate->voucher_id]);

        // A converted estimate can't be converted, edited or deleted again.
        $this->actingAs($this->company->user)
            ->post(route('companies.documents.convert', [$this->company, 'estimate', $estimate]))
            ->assertSessionHas('error');
        $this->actingAs($this->company->user)
            ->delete(route('companies.documents.destroy', [$this->company, 'estimate', $estimate]))
            ->assertSessionHas('error');
    }

    public function test_edit_cancel_and_delete(): void
    {
        $this->createEstimate();
        $estimate = $this->company->businessDocuments()->firstOrFail();

        $this->actingAs($this->company->user)
            ->put(route('companies.documents.update', [$this->company, 'estimate', $estimate]), [
                'date' => '2026-07-02', 'party_account_id' => $this->customer->id,
                'lines' => [['account_id' => $this->sales->id, 'taxable' => 1000, 'rate' => 5]],
            ])
            ->assertSessionHasNoErrors();
        $this->assertSame('1050.00', $estimate->fresh()->amount);

        $this->actingAs($this->company->user)->delete(route('companies.documents.destroy', [$this->company, 'estimate', $estimate]));
        $this->assertSame('cancelled', $estimate->fresh()->status);

        $this->actingAs($this->company->user)->delete(route('companies.documents.destroy', [$this->company, 'estimate', $estimate]));
        $this->assertModelMissing($estimate);
    }

    public function test_numbering_list_and_validation(): void
    {
        $this->createEstimate();
        $this->createEstimate();
        $this->assertSame([1, 2], $this->company->businessDocuments()->orderBy('id')->pluck('number')->all());

        $this->actingAs($this->company->user)
            ->get(route('companies.documents.index', [$this->company, 'estimate']))
            ->assertOk()
            ->assertSee('Pune Stores');

        $this->createEstimate(['valid_until' => '2026-06-01'])->assertSessionHasErrors('valid_until');
        $this->actingAs($this->company->user)
            ->get(route('companies.documents.index', [$this->company, 'nonsense']))
            ->assertNotFound();
    }

    public function test_company_with_estimates_can_still_be_deleted_and_backed_up(): void
    {
        $this->createEstimate();

        $backup = \App\Support\CompanyBackup::export($this->company);
        $this->assertCount(1, $backup['tables']['business_documents']);
        $this->assertCount(2, $backup['tables']['business_document_lines']);

        $copy = \App\Support\CompanyBackup::restore($backup, $this->company->user, 'Copy');
        $this->assertSame(1, $copy->businessDocuments()->count());

        $this->actingAs($this->company->user)->delete(route('companies.destroy', $this->company))->assertRedirect();
        $this->assertModelMissing($this->company);
    }
}
