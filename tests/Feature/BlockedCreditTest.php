<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BlockedCreditTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected Account $purchases;

    protected Account $staffWelfare;

    protected Account $supplier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = User::factory()->create()->companies()->create([
            'name' => 'Acme Traders', 'gstin' => '27AAAAA0000A1Z5', 'financial_year' => '2026-27', 'currency' => 'INR',
        ]);

        $this->purchases = $this->ledger('Purchases', 'PURCHASE_ACCOUNTS');
        $this->staffWelfare = $this->ledger('Staff Welfare', 'INDIRECT_EXPENSES', ['itc_blocked' => true]);
        $this->supplier = $this->ledger('Caterer', 'SUNDRY_CREDITORS', ['state_code' => '27']);
    }

    protected function ledger(string $name, string $code, array $extra = []): Account
    {
        $group = $this->company->accountGroups()->where('code', $code)->firstOrFail();

        return $this->company->accounts()->create($extra + ['name' => $name, 'account_group_id' => $group->id, 'account_type' => $group->nature]);
    }

    protected function bill(array $lines)
    {
        return $this->actingAs($this->company->user)
            ->post(route('companies.gst-invoices.store', $this->company), [
                'type' => 'purchase', 'date' => '2026-07-05', 'party_account_id' => $this->supplier->id, 'lines' => $lines,
            ]);
    }

    public function test_blocked_tax_is_added_to_expense_not_input_credit(): void
    {
        $this->bill([
            ['account_id' => $this->staffWelfare->id, 'taxable' => 10000, 'rate' => 5],  // GST 500, blocked
            ['account_id' => $this->purchases->id, 'taxable' => 20000, 'rate' => 18],    // GST 3,600, claimable
        ])->assertSessionHasNoErrors();

        $voucher = $this->company->vouchers()->firstOrFail();
        $entry = fn (Account $a) => $voucher->entries()->where('account_id', $a->id)->first();

        $this->assertSame('10500.00', $entry($this->staffWelfare)->debit);
        $this->assertSame('20000.00', $entry($this->purchases)->debit);
        $this->assertSame('34100.00', $entry($this->supplier)->credit);

        $inputCgst = $this->company->accounts()->where('code', 'GST_INPUT_CGST')->firstOrFail();
        $this->assertSame('1800.00', $entry($inputCgst)->debit);

        $this->assertSame([false, true], $voucher->gstLines()->orderBy('id')->pluck('itc_eligible')->all());
        $this->assertSame((float) $voucher->entries()->sum('debit'), (float) $voucher->entries()->sum('credit'));
    }

    public function test_gstr3b_excludes_blocked_credit(): void
    {
        $this->bill([
            ['account_id' => $this->staffWelfare->id, 'taxable' => 10000, 'rate' => 5],
            ['account_id' => $this->purchases->id, 'taxable' => 20000, 'rate' => 18],
        ]);

        $response = $this->actingAs($this->company->user)
            ->get(route('companies.gst.gstr3b', [$this->company, 'month' => '2026-07']))
            ->assertOk()
            ->assertSee('4(D) Ineligible ITC');

        $this->assertSame(1800.0, $response->viewData('itc')->cgst);
        $this->assertSame(250.0, $response->viewData('ineligible')->cgst);
        $this->assertSame(1800.0, $response->viewData('carryForward')->cgst);
    }

    public function test_expense_ledgers_allowed_on_purchase_bills_but_not_sales(): void
    {
        $customer = $this->ledger('Customer', 'SUNDRY_DEBTORS');

        $this->actingAs($this->company->user)
            ->post(route('companies.gst-invoices.store', $this->company), [
                'type' => 'sales', 'date' => '2026-07-05', 'party_account_id' => $customer->id,
                'lines' => [['account_id' => $this->staffWelfare->id, 'taxable' => 100, 'rate' => 5]],
            ])
            ->assertSessionHasErrors('lines.0.account_id');

        $this->actingAs($this->company->user)
            ->get(route('companies.gst-invoices.create', [$this->company, 'type' => 'purchase']))
            ->assertOk()
            ->assertSee('Staff Welfare');
    }

    public function test_ledger_form_saves_flag(): void
    {
        $group = $this->company->accountGroups()->where('code', 'INDIRECT_EXPENSES')->value('id');

        $this->actingAs($this->company->user)
            ->post(route('companies.accounts.store', $this->company), [
                'name' => 'Motor Car Expenses', 'account_group_id' => $group, 'opening_balance_type' => 'dr',
                'is_active' => '1', 'itc_blocked' => '1',
            ])
            ->assertSessionHasNoErrors();

        $this->assertTrue($this->company->accounts()->where('name', 'Motor Car Expenses')->value('itc_blocked'));
    }
}
