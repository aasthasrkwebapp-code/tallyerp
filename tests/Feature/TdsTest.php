<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TdsTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected Account $contractor;

    protected Account $consultant;

    protected Account $expense;

    protected Account $bank;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = User::factory()->create()->companies()->create([
            'name' => 'Acme Traders', 'financial_year' => '2026-27', 'currency' => 'INR',
        ]);

        $this->contractor = $this->ledger('Ravi Contractors', 'SUNDRY_CREDITORS', ['pan' => 'ABCDE1234F', 'tds_section' => '194C-O']);
        $this->consultant = $this->ledger('No-PAN Consultant', 'SUNDRY_CREDITORS', ['tds_section' => '194J-P']);
        $this->expense = $this->ledger('Repairs', 'INDIRECT_EXPENSES');
        $this->bank = $this->ledger('HDFC Bank', 'BANK_ACCOUNTS');
    }

    protected function ledger(string $name, string $code, array $extra = []): Account
    {
        $group = $this->company->accountGroups()->where('code', $code)->firstOrFail();

        return $this->company->accounts()->create($extra + [
            'name' => $name, 'account_group_id' => $group->id, 'account_type' => $group->nature,
        ]);
    }

    protected function bill(Account $party, float $amount, string $date = '2026-06-10')
    {
        return $this->actingAs($this->company->user)
            ->post(route('companies.tds.store', $this->company), [
                'date' => $date,
                'party_account_id' => $party->id,
                'expense_account_id' => $this->expense->id,
                'amount' => $amount,
            ]);
    }

    public function test_bill_deducts_tds_at_section_rate(): void
    {
        $this->bill($this->contractor, 100000)->assertSessionHasNoErrors();

        $voucher = $this->company->vouchers()->firstOrFail();
        $this->assertSame('journal', $voucher->type);

        $tdsLedger = $this->company->accounts()->where('code', 'TDS_194C')->firstOrFail();
        $this->assertSame('TDS Payable - 194C', $tdsLedger->name);
        $this->assertSame('2000.00', $voucher->entries()->where('account_id', $tdsLedger->id)->value('credit'));
        $this->assertSame('98000.00', $voucher->entries()->where('account_id', $this->contractor->id)->value('credit'));
        $this->assertSame('100000.00', $voucher->entries()->where('account_id', $this->expense->id)->value('debit'));

        $deduction = $this->company->tdsDeductions()->firstOrFail();
        $this->assertSame('2.00', $deduction->rate);
        $this->assertSame('2000.00', $deduction->tds_amount);
    }

    public function test_no_pan_means_twenty_percent(): void
    {
        $this->bill($this->consultant, 10000)->assertSessionHasNoErrors();

        $this->assertSame('2000.00', $this->company->tdsDeductions()->value('tds_amount'));
        $this->assertSame('20.00', $this->company->tdsDeductions()->value('rate'));
    }

    public function test_party_without_section_is_rejected(): void
    {
        $plain = $this->ledger('Plain Supplier', 'SUNDRY_CREDITORS');

        $this->bill($plain, 1000)->assertSessionHasErrors('party_account_id');
        $this->assertDatabaseCount('vouchers', 0);
    }

    public function test_report_by_section_and_payable_after_deposit(): void
    {
        $this->bill($this->contractor, 100000);
        $this->bill($this->contractor, 50000);
        $this->bill($this->consultant, 10000);

        // Deposit 194C TDS of 2,000 with the government.
        $tdsLedger = $this->company->accounts()->where('code', 'TDS_194C')->firstOrFail();
        $this->actingAs($this->company->user)
            ->post(route('companies.vouchers.store', $this->company), [
                'type' => 'payment',
                'date' => '2026-07-07',
                'entries' => [
                    ['account_id' => $tdsLedger->id, 'debit' => 2000],
                    ['account_id' => $this->bank->id, 'credit' => 2000],
                ],
            ])
            ->assertSessionHasNoErrors();

        $response = $this->actingAs($this->company->user)
            ->get(route('companies.tds.index', $this->company))
            ->assertOk()
            ->assertSee('ABCDE1234F');

        $sections = $response->viewData('sections')->keyBy('section');
        $this->assertSame(3000.0, $sections['194C']->tds);
        $this->assertSame(150000.0, $sections['194C']->base);
        $this->assertSame(2000.0, $sections['194J']->tds);

        $payable = $response->viewData('payable')->keyBy(fn ($p) => $p->ledger->name);
        $this->assertSame(1000.0, $payable['TDS Payable - 194C']->balance);
        $this->assertSame(2000.0, $payable['TDS Payable - 194J']->balance);
    }

    public function test_tds_bill_cannot_be_edited(): void
    {
        $this->bill($this->contractor, 1000);
        $voucher = $this->company->vouchers()->firstOrFail();

        $this->actingAs($this->company->user)
            ->get(route('companies.vouchers.edit', [$this->company, $voucher]))
            ->assertSessionHas('error');
    }

    public function test_ledger_saves_pan_and_section(): void
    {
        $group = $this->company->accountGroups()->where('code', 'SUNDRY_CREDITORS')->firstOrFail();

        $this->actingAs($this->company->user)
            ->post(route('companies.accounts.store', $this->company), [
                'name' => 'Landlord',
                'account_group_id' => $group->id,
                'opening_balance_type' => 'cr',
                'pan' => 'pqrst6789k',
                'tds_section' => '194I-LB',
                'is_active' => '1',
            ])
            ->assertSessionHasNoErrors();

        $landlord = $this->company->accounts()->where('name', 'Landlord')->firstOrFail();
        $this->assertSame('PQRST6789K', $landlord->pan);
        $this->assertSame('194I-LB', $landlord->tds_section);

        $this->actingAs($this->company->user)
            ->get(route('companies.tds.create', $this->company))
            ->assertOk()
            ->assertSee('Landlord');
    }

    public function test_other_users_blocked(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('companies.tds.index', $this->company))
            ->assertForbidden();
    }
}
