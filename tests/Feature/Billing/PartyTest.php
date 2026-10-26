<?php

namespace Tests\Feature\Billing;

use App\Models\Account;
use App\Models\AccountGroup;
use App\Models\Party;
use App\Services\Billing\InvoiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PartyTest extends TestCase
{
    use RefreshDatabase;

    protected $owner;
    protected $company;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->owner, $this->company] = $this->createCompanyWithOwner();
        $this->actingAs($this->owner);
    }

    protected function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Sharma Traders',
            'account_group_id' => AccountGroup::idFor('sundry_debtors'),
            'opening_balance' => '5,000',
            'opening_balance_type' => 'Dr',
            'maintain_bill_wise' => '1',
            'credit_period_days' => '15',
            'credit_limit' => '100000',
            'gst_registration_type' => 'regular',
            'gstin' => '27abcde1234f1z5',
            'state' => 'Maharashtra',
            'billing_address' => 'Shop 4, MG Road',
            'city' => 'Pune',
            'pincode' => '411001',
            'phone' => '+91 98200 00000',
            'email' => 'sharma@example.com',
        ], $overrides);
    }

    public function test_customer_is_created_with_ledger_and_details(): void
    {
        $this->post(route('customers.store'), $this->payload())->assertRedirect()->assertSessionHasNoErrors();

        $ledger = Account::where('name', 'Sharma Traders')->firstOrFail();
        $this->assertSame('party', $ledger->account_type);
        $this->assertTrue($ledger->maintain_bill_wise);
        $this->assertSame('5000.00', $ledger->opening_balance);
        $this->assertSame(15, $ledger->credit_period_days);
        $this->assertSame('27ABCDE1234F1Z5', $ledger->partyDetails->gstin);
        $this->assertSame('ABCDE1234F', $ledger->partyDetails->pan); // taken from the GSTIN
        $this->assertSame("Shop 4, MG Road\nPune - 411001\nMaharashtra", $ledger->partyDetails->fullAddress());

        $this->get(route('customers.index'))->assertOk()->assertSee('Sharma Traders')->assertSee('5,000.00 Dr');
        $this->get(route('customers.show', $ledger))->assertOk()->assertSee('27ABCDE1234F1Z5');
        $this->get(route('customers.edit', $ledger))->assertOk();
    }

    public function test_validation(): void
    {
        $this->post(route('customers.store'), $this->payload(['state' => 'Gujarat']))->assertSessionHasErrors('gstin');
        $this->post(route('customers.store'), $this->payload(['gstin' => '']))->assertSessionHasErrors('gstin');
        $this->post(route('customers.store'), $this->payload(['account_group_id' => AccountGroup::idFor('sundry_creditors')]))->assertSessionHasErrors('account_group_id');
        $this->post(route('customers.store'), $this->payload(['pincode' => '12']))->assertSessionHasErrors('pincode');
        $this->post(route('customers.store'), $this->payload(['gst_registration_type' => 'unregistered', 'gstin' => '', 'state' => '']))->assertSessionHasErrors('state');
        $this->assertSame(0, Party::count());

        // Unregistered customer without GSTIN is fine.
        $this->post(route('customers.store'), $this->payload(['gst_registration_type' => 'consumer', 'gstin' => '']))->assertSessionHasNoErrors();
    }

    public function test_supplier_lives_under_sundry_creditors(): void
    {
        $this->post(route('suppliers.store'), $this->payload(['name' => 'Kumar Steel', 'account_group_id' => AccountGroup::idFor('sundry_creditors'), 'opening_balance_type' => 'Cr']))
            ->assertSessionHasNoErrors();
        $supplier = Account::where('name', 'Kumar Steel')->firstOrFail();

        $this->get(route('suppliers.index'))->assertSee('Kumar Steel');
        $this->get(route('customers.index'))->assertDontSee('Kumar Steel');
        $this->get(route('customers.show', $supplier))->assertNotFound();
    }

    public function test_ledger_created_elsewhere_gets_details_on_edit(): void
    {
        $ledger = Account::create(['name' => 'Walk-in', 'account_group_id' => AccountGroup::idFor('sundry_debtors'), 'account_type' => 'party']);
        $this->get(route('customers.show', $ledger))->assertOk()->assertSee('No contact or GST details yet');

        $this->put(route('customers.update', $ledger), $this->payload(['name' => 'Walk-in', 'gst_registration_type' => 'consumer', 'gstin' => '']))
            ->assertSessionHasNoErrors();
        $this->assertSame('Maharashtra', $ledger->fresh()->partyDetails->state);
    }

    public function test_party_with_invoices_cannot_be_deleted(): void
    {
        $this->post(route('customers.store'), $this->payload());
        $ledger = Account::where('name', 'Sharma Traders')->firstOrFail();

        app(InvoiceService::class)->save(null, 'sales', [
            'date' => '2026-09-01', 'party_account_id' => $ledger->id,
            'items' => [['account_id' => Account::idFor('sales'), 'quantity' => '1', 'rate' => '10', 'gst_rate' => '0']],
        ], $this->owner, 'draft');

        $this->delete(route('customers.destroy', $ledger))->assertSessionHas('error');
        $this->assertModelExists($ledger);

        $this->post(route('customers.store'), $this->payload(['name' => 'Temp', 'gstin' => '', 'gst_registration_type' => 'consumer']));
        $temp = Account::where('name', 'Temp')->firstOrFail();
        $this->delete(route('customers.destroy', $temp))->assertRedirect(route('customers.index'));
        $this->assertModelMissing($temp);
        $this->assertSame(1, Party::count());
    }
}
