<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VouchersTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected Account $cash;

    protected Account $bank;

    protected Account $rent;

    protected Account $sales;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = User::factory()->create()->companies()->create([
            'name' => 'Acme Traders',
            'financial_year' => '2026-27',
            'currency' => 'INR',
        ]);

        $this->cash = $this->ledger('Cash', 'CASH_IN_HAND');
        $this->bank = $this->ledger('HDFC Bank', 'BANK_ACCOUNTS');
        $this->rent = $this->ledger('Rent', 'INDIRECT_EXPENSES');
        $this->sales = $this->ledger('Sales', 'SALES_ACCOUNTS');
    }

    protected function ledger(string $name, string $groupCode): Account
    {
        $group = $this->company->accountGroups()->where('code', $groupCode)->firstOrFail();

        return $this->company->accounts()->create([
            'name' => $name,
            'account_group_id' => $group->id,
            'account_type' => $group->nature,
        ]);
    }

    protected function voucher(string $type, array $entries, string $date = '2026-06-15')
    {
        return $this->actingAs($this->company->user)->post(route('companies.vouchers.store', $this->company), [
            'type' => $type,
            'date' => $date,
            'narration' => 'Test',
            'entries' => $entries,
        ]);
    }

    public function test_balanced_payment_is_saved_with_sequential_numbers(): void
    {
        $entries = [
            ['account_id' => $this->rent->id, 'debit' => '1500', 'credit' => ''],
            ['account_id' => $this->cash->id, 'debit' => '', 'credit' => '1500'],
        ];

        $this->voucher('payment', $entries)->assertSessionHasNoErrors();
        $this->voucher('payment', $entries)->assertSessionHasNoErrors();

        $vouchers = $this->company->vouchers()->orderBy('id')->get();
        $this->assertSame([1, 2], $vouchers->pluck('number')->all());
        $this->assertSame('1500.00', $vouchers[0]->amount);
        $this->assertSame(2, $vouchers[0]->entries()->count());

        $this->actingAs($this->company->user)
            ->get(route('companies.vouchers.show', [$this->company, $vouchers[0]]))
            ->assertOk()
            ->assertSee('Payment #1')
            ->assertSee('Rent');

        $this->actingAs($this->company->user)
            ->get(route('companies.vouchers.index', $this->company))
            ->assertOk()
            ->assertSee('1,500.00');
    }

    public function test_numbering_is_separate_per_voucher_type(): void
    {
        $this->voucher('payment', [
            ['account_id' => $this->rent->id, 'debit' => '100'],
            ['account_id' => $this->cash->id, 'credit' => '100'],
        ]);
        $this->voucher('receipt', [
            ['account_id' => $this->cash->id, 'debit' => '100'],
            ['account_id' => $this->sales->id, 'credit' => '100'],
        ])->assertSessionHasNoErrors();

        $this->assertSame(1, $this->company->vouchers()->where('type', 'receipt')->value('number'));
    }

    public function test_unbalanced_voucher_is_rejected(): void
    {
        $this->voucher('journal', [
            ['account_id' => $this->rent->id, 'debit' => '1000'],
            ['account_id' => $this->cash->id, 'credit' => '900'],
        ])->assertSessionHasErrors('entries');

        $this->assertDatabaseCount('vouchers', 0);
    }

    public function test_row_with_both_debit_and_credit_is_rejected(): void
    {
        $this->voucher('journal', [
            ['account_id' => $this->rent->id, 'debit' => '100', 'credit' => '100'],
            ['account_id' => $this->cash->id, 'credit' => '100'],
        ])->assertSessionHasErrors('entries.0.debit');
    }

    public function test_contra_only_allows_cash_and_bank(): void
    {
        $this->voucher('contra', [
            ['account_id' => $this->bank->id, 'debit' => '500'],
            ['account_id' => $this->rent->id, 'credit' => '500'],
        ])->assertSessionHasErrors('entries');

        $this->voucher('contra', [
            ['account_id' => $this->bank->id, 'debit' => '500'],
            ['account_id' => $this->cash->id, 'credit' => '500'],
        ])->assertSessionHasNoErrors();
    }

    public function test_payment_must_credit_cash_or_bank(): void
    {
        $this->voucher('payment', [
            ['account_id' => $this->rent->id, 'debit' => '500'],
            ['account_id' => $this->sales->id, 'credit' => '500'],
        ])->assertSessionHasErrors('entries');
    }

    public function test_date_must_be_inside_financial_year(): void
    {
        $this->voucher('journal', [
            ['account_id' => $this->rent->id, 'debit' => '100'],
            ['account_id' => $this->cash->id, 'credit' => '100'],
        ], '2027-04-01')->assertSessionHasErrors('date');
    }

    public function test_cannot_use_another_companys_ledger(): void
    {
        $other = User::factory()->create()->companies()->create([
            'name' => 'Other', 'financial_year' => '2026-27', 'currency' => 'INR',
        ]);
        $foreign = $other->accounts()->create([
            'name' => 'Foreign Cash',
            'account_group_id' => $other->accountGroups()->where('code', 'CASH_IN_HAND')->value('id'),
            'account_type' => 'assets',
        ]);

        $this->voucher('journal', [
            ['account_id' => $this->rent->id, 'debit' => '100'],
            ['account_id' => $foreign->id, 'credit' => '100'],
        ])->assertSessionHasErrors('entries.1.account_id');
    }

    public function test_ledger_with_entries_cannot_be_deleted(): void
    {
        $this->voucher('payment', [
            ['account_id' => $this->rent->id, 'debit' => '100'],
            ['account_id' => $this->cash->id, 'credit' => '100'],
        ]);

        $this->actingAs($this->company->user)
            ->delete(route('companies.accounts.destroy', [$this->company, $this->rent]))
            ->assertSessionHas('error');

        $this->assertModelExists($this->rent);
    }

    public function test_deleting_voucher_removes_its_entries(): void
    {
        $this->voucher('payment', [
            ['account_id' => $this->rent->id, 'debit' => '100'],
            ['account_id' => $this->cash->id, 'credit' => '100'],
        ]);
        $voucher = $this->company->vouchers()->first();

        $this->actingAs($this->company->user)
            ->delete(route('companies.vouchers.destroy', [$this->company, $voucher]))
            ->assertRedirect(route('companies.vouchers.index', $this->company));

        $this->assertDatabaseCount('voucher_entries', 0);
    }

    public function test_other_users_cannot_view_vouchers(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('companies.vouchers.index', $this->company))
            ->assertForbidden();
    }
}
