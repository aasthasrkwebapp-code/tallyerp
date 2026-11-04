<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Company;
use App\Models\User;
use App\Models\VoucherEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Bank opening 50,000 Dr. Deposit 20,000 (receipt) and cheque 8,000 (payment rent).
 */
class BankingTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected Account $bank;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = User::factory()->create()->companies()->create([
            'name' => 'Acme Traders', 'financial_year' => '2026-27', 'currency' => 'INR',
        ]);

        $this->bank = $this->ledger('HDFC Bank', 'BANK_ACCOUNTS', 50000);
        $sales = $this->ledger('Sales', 'SALES_ACCOUNTS');
        $rent = $this->ledger('Rent', 'INDIRECT_EXPENSES');

        $this->voucher('receipt', '2026-06-01', $this->bank, $sales, 20000);
        $this->voucher('payment', '2026-06-05', $rent, $this->bank, 8000);
    }

    protected function ledger(string $name, string $code, float $opening = 0): Account
    {
        $group = $this->company->accountGroups()->where('code', $code)->firstOrFail();

        return $this->company->accounts()->create([
            'name' => $name,
            'account_group_id' => $group->id,
            'account_type' => $group->nature,
            'opening_balance' => $opening,
        ]);
    }

    protected function voucher(string $type, string $date, Account $debit, Account $credit, float $amount): void
    {
        $this->actingAs($this->company->user)
            ->post(route('companies.vouchers.store', $this->company), [
                'type' => $type,
                'date' => $date,
                'entries' => [
                    ['account_id' => $debit->id, 'debit' => $amount],
                    ['account_id' => $credit->id, 'credit' => $amount],
                ],
            ])
            ->assertSessionHasNoErrors();
    }

    protected function page(array $query = [])
    {
        return $this->actingAs($this->company->user)
            ->get(route('companies.banking.reconciliation', [$this->company, 'account' => $this->bank->id, ...$query]));
    }

    protected function bankEntry(string $column): VoucherEntry
    {
        return $this->bank->entries()->where($column, '>', 0)->firstOrFail();
    }

    public function test_unreconciled_summary(): void
    {
        $summary = $this->page()->assertOk()->viewData('summary');

        $this->assertSame(62000.0, $summary['books']);
        $this->assertSame(20000.0, $summary['not_in_bank_debits']);
        $this->assertSame(8000.0, $summary['not_in_bank_credits']);
        $this->assertSame(50000.0, $summary['bank']);
    }

    public function test_clearing_entries_updates_bank_balance(): void
    {
        $deposit = $this->bankEntry('debit');
        $cheque = $this->bankEntry('credit');

        $this->actingAs($this->company->user)
            ->put(route('companies.banking.reconciliation.update', $this->company), [
                'account_id' => $this->bank->id,
                'entries' => [
                    $deposit->id => ['bank_date' => '2026-06-03', 'instrument_no' => 'UTR123'],
                    $cheque->id => ['bank_date' => '', 'instrument_no' => '000451'],
                ],
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('2026-06-03', $deposit->fresh()->bank_date->toDateString());
        $this->assertSame('000451', $cheque->fresh()->instrument_no);

        $response = $this->page();
        $this->assertSame(70000.0, $response->viewData('summary')['bank']);
        $this->assertCount(1, $response->viewData('entries'));
        $this->assertCount(2, $this->page(['show' => 'all'])->viewData('entries'));
    }

    public function test_entry_cleared_after_as_on_date_is_still_pending(): void
    {
        $deposit = $this->bankEntry('debit');
        $deposit->update(['bank_date' => '2026-07-02']);

        $summary = $this->page(['to' => '2026-06-30'])->viewData('summary');
        $this->assertSame(20000.0, $summary['not_in_bank_debits']);
    }

    public function test_bank_date_cannot_be_before_voucher_date(): void
    {
        $deposit = $this->bankEntry('debit');

        $this->actingAs($this->company->user)
            ->put(route('companies.banking.reconciliation.update', $this->company), [
                'account_id' => $this->bank->id,
                'entries' => [$deposit->id => ['bank_date' => '2026-05-01']],
            ])
            ->assertSessionHasErrors("entries.$deposit->id.bank_date");
    }

    public function test_non_bank_ledger_is_not_reconcilable(): void
    {
        $rent = $this->company->accounts()->where('name', 'Rent')->firstOrFail();

        $this->actingAs($this->company->user)
            ->get(route('companies.banking.reconciliation', [$this->company, 'account' => $rent->id]))
            ->assertNotFound();
    }

    public function test_cannot_touch_another_companys_bank_entries(): void
    {
        $other = User::factory()->create()->companies()->create([
            'name' => 'Other', 'financial_year' => '2026-27', 'currency' => 'INR',
        ]);

        $this->actingAs($other->user)
            ->put(route('companies.banking.reconciliation.update', $other), [
                'account_id' => $this->bank->id,
                'entries' => [$this->bankEntry('debit')->id => ['bank_date' => '2026-06-03']],
            ])
            ->assertNotFound();

        $this->assertNull($this->bankEntry('debit')->bank_date);
    }
}
