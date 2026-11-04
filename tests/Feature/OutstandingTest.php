<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OutstandingTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected Account $cash;

    protected Account $sales;

    protected Account $purchases;

    protected Account $customer;

    protected Account $supplier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = User::factory()->create()->companies()->create([
            'name' => 'Acme Traders', 'financial_year' => '2026-27', 'currency' => 'INR',
        ]);

        $this->cash = $this->ledger('Cash', 'CASH_IN_HAND');
        $this->sales = $this->ledger('Sales', 'SALES_ACCOUNTS');
        $this->purchases = $this->ledger('Purchases', 'PURCHASE_ACCOUNTS');
        $this->customer = $this->ledger('Pune Stores', 'SUNDRY_DEBTORS', 1000, 'dr'); // opening bill on 1 Apr
        $this->supplier = $this->ledger('Mumbai Wholesale', 'SUNDRY_CREDITORS');
    }

    protected function ledger(string $name, string $code, float $opening = 0, string $type = 'dr'): Account
    {
        $group = $this->company->accountGroups()->where('code', $code)->firstOrFail();

        return $this->company->accounts()->create([
            'name' => $name,
            'account_group_id' => $group->id,
            'account_type' => $group->nature,
            'opening_balance' => $opening,
            'opening_balance_type' => $type,
        ]);
    }

    protected function journal(string $date, Account $debit, Account $credit, float $amount): void
    {
        $this->actingAs($this->company->user)
            ->post(route('companies.vouchers.store', $this->company), [
                'type' => 'journal',
                'date' => $date,
                'entries' => [
                    ['account_id' => $debit->id, 'debit' => $amount],
                    ['account_id' => $credit->id, 'credit' => $amount],
                ],
            ])
            ->assertSessionHasNoErrors();
    }

    protected function report(string $kind, string $to)
    {
        return $this->actingAs($this->company->user)
            ->get(route('companies.outstanding', [$this->company, $kind, 'to' => $to]))
            ->assertOk();
    }

    public function test_receivables_are_aged_and_settled_oldest_first(): void
    {
        // As on 30 Jun: opening 1,000 (90 days old), bill 5,000 on 15 May (46 days),
        // bill 2,000 on 20 Jun (10 days). Receipt 1,500 settles the opening and 500 of May.
        $this->journal('2026-05-15', $this->customer, $this->sales, 5000);
        $this->journal('2026-06-20', $this->customer, $this->sales, 2000);
        $this->journal('2026-06-25', $this->cash, $this->customer, 1500);

        $response = $this->report('receivables', '2026-06-30');
        $row = $response->viewData('parties')->first();

        $this->assertSame('Pune Stores', $row->party->name);
        $this->assertSame(['0-30' => 2000.0, '31-60' => 4500.0, '61-90' => 0.0, '90+' => 0.0], $row->buckets);
        $this->assertSame(6500.0, $row->total);
        $this->assertSame(6500.0, $response->viewData('grandTotal'));
    }

    public function test_old_bill_falls_in_90_plus(): void
    {
        $response = $this->report('receivables', '2026-08-15');

        $this->assertSame(1000.0, $response->viewData('parties')->first()->buckets['90+']);
        $response->assertSee('text-danger', false);
    }

    public function test_payables_use_credit_balances(): void
    {
        $this->journal('2026-06-01', $this->purchases, $this->supplier, 8000);
        $this->journal('2026-06-10', $this->supplier, $this->cash, 3000);

        $response = $this->report('payables', '2026-06-30');
        $row = $response->viewData('parties')->first();

        $this->assertSame('Mumbai Wholesale', $row->party->name);
        $this->assertSame(5000.0, $row->buckets['0-30']);
        $this->assertSame(5000.0, $row->total);

        // Customers are not payables.
        $this->assertCount(1, $response->viewData('parties'));
    }

    public function test_overpayment_shows_as_advance(): void
    {
        $this->journal('2026-06-01', $this->cash, $this->customer, 1500);

        $row = $this->report('receivables', '2026-06-30')->viewData('parties')->first();

        $this->assertSame(500.0, $row->advance);
        $this->assertSame(-500.0, $row->total);
    }

    public function test_settled_party_is_hidden(): void
    {
        $this->journal('2026-06-01', $this->cash, $this->customer, 1000);

        $this->assertCount(0, $this->report('receivables', '2026-06-30')->viewData('parties'));
    }

    public function test_unknown_kind_and_other_users_are_rejected(): void
    {
        $this->actingAs($this->company->user)
            ->get('/companies/'.$this->company->id.'/outstanding/everything')
            ->assertNotFound();

        $this->actingAs(User::factory()->create())
            ->get(route('companies.outstanding', [$this->company, 'receivables']))
            ->assertForbidden();
    }
}
