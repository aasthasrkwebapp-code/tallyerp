<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected Account $sales;

    protected Account $purchases;

    protected Account $customer;

    protected Account $supplier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = User::factory()->create()->companies()->create([
            'name' => 'Acme Traders', 'gstin' => '27AAAAA0000A1Z5', 'financial_year' => '2026-27', 'currency' => 'INR',
        ]);
        $make = function (string $name, string $code, array $extra = []) {
            $group = $this->company->accountGroups()->where('code', $code)->firstOrFail();

            return $this->company->accounts()->create($extra + ['name' => $name, 'account_group_id' => $group->id, 'account_type' => $group->nature]);
        };
        $this->sales = $make('Sales', 'SALES_ACCOUNTS');
        $this->purchases = $make('Purchases', 'PURCHASE_ACCOUNTS');
        $this->customer = $make('Pune Stores', 'SUNDRY_DEBTORS', ['state_code' => '27']);
        $this->supplier = $make('Mumbai Wholesale', 'SUNDRY_CREDITORS', ['state_code' => '27']);
    }

    protected function order(string $type, Account $party, Account $ledger)
    {
        return $this->actingAs($this->company->user)
            ->post(route('companies.documents.store', [$this->company, $type]), [
                'date' => '2026-07-01', 'party_account_id' => $party->id,
                'lines' => [['account_id' => $ledger->id, 'taxable' => 10000, 'rate' => 18]],
            ]);
    }

    public function test_sale_order_converts_to_sales_invoice(): void
    {
        $this->order('sale_order', $this->customer, $this->sales)->assertSessionHasNoErrors();
        $order = $this->company->businessDocuments()->firstOrFail();

        $this->actingAs($this->company->user)
            ->get(route('companies.documents.show', [$this->company, 'sale_order', $order]))
            ->assertSee('SALE ORDER')
            ->assertSee('Convert to Sales Invoice');

        $this->actingAs($this->company->user)
            ->post(route('companies.documents.convert', [$this->company, 'sale_order', $order]))
            ->assertRedirect(route('companies.gst-invoices.create', [$this->company, 'type' => 'sales', 'from_document' => $order->id]));
    }

    public function test_purchase_order_uses_purchase_ledgers_and_converts_to_purchase_invoice(): void
    {
        $this->actingAs($this->company->user)
            ->get(route('companies.documents.create', [$this->company, 'purchase_order']))
            ->assertOk()
            ->assertSee('Purchases')
            ->assertDontSee('>Sales</option>', false);

        $this->order('purchase_order', $this->supplier, $this->purchases)->assertSessionHasNoErrors();
        $order = $this->company->businessDocuments()->firstOrFail();

        $this->actingAs($this->company->user)
            ->get(route('companies.documents.show', [$this->company, 'purchase_order', $order]))
            ->assertSee('PURCHASE ORDER')
            ->assertSee('Supplier')
            ->assertSee('Convert to Purchase Invoice');

        $this->actingAs($this->company->user)
            ->post(route('companies.gst-invoices.store', $this->company), [
                'type' => 'purchase', 'date' => '2026-07-10', 'party_account_id' => $this->supplier->id, 'document_id' => $order->id,
                'lines' => [['account_id' => $this->purchases->id, 'taxable' => 10000, 'rate' => 18]],
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('converted', $order->fresh()->status);
    }

    public function test_each_type_numbers_separately_and_tabs_show(): void
    {
        $this->order('sale_order', $this->customer, $this->sales);
        $this->order('purchase_order', $this->supplier, $this->purchases);

        $this->assertSame([1, 1], $this->company->businessDocuments()->orderBy('id')->pluck('number')->all());

        $this->actingAs($this->company->user)
            ->get(route('companies.documents.index', [$this->company, 'sale_order']))
            ->assertOk()
            ->assertSee('Estimates')
            ->assertSee('Sale Orders')
            ->assertSee('Purchase Orders');
    }
}
