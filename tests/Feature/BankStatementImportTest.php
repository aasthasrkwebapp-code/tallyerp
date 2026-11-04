<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Company;
use App\Models\User;
use App\Support\BankStatement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class BankStatementImportTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected Account $bank;

    protected Account $sales;

    protected Account $rent;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = User::factory()->create()->companies()->create([
            'name' => 'Acme Traders', 'financial_year' => '2026-27', 'currency' => 'INR',
        ]);

        $this->bank = $this->ledger('HDFC Bank', 'BANK_ACCOUNTS');
        $this->sales = $this->ledger('Sales', 'SALES_ACCOUNTS');
        $this->rent = $this->ledger('Rent', 'INDIRECT_EXPENSES');

        $this->voucher('receipt', '2026-06-01', $this->bank, $this->sales, 20000);
        $this->voucher('payment', '2026-06-05', $this->rent, $this->bank, 8000);
        $this->voucher('payment', '2026-06-05', $this->rent, $this->bank, 8000); // second cheque, same amount
        $this->bank->entries()->where('credit', '>', 0)->orderBy('id')->get()
            ->each(fn ($e, $i) => $e->update(['instrument_no' => $i === 0 ? '000451' : '000452']));
    }

    protected function ledger(string $name, string $code): Account
    {
        $group = $this->company->accountGroups()->where('code', $code)->firstOrFail();

        return $this->company->accounts()->create(['name' => $name, 'account_group_id' => $group->id, 'account_type' => $group->nature]);
    }

    protected function voucher(string $type, string $date, Account $debit, Account $credit, float $amount): void
    {
        $this->actingAs($this->company->user)
            ->post(route('companies.vouchers.store', $this->company), [
                'type' => $type, 'date' => $date,
                'entries' => [['account_id' => $debit->id, 'debit' => $amount], ['account_id' => $credit->id, 'credit' => $amount]],
            ])
            ->assertSessionHasNoErrors();
    }

    protected function upload(string $csv)
    {
        return $this->actingAs($this->company->user)
            ->post(route('companies.banking.reconciliation.import', $this->company), [
                'account_id' => $this->bank->id,
                'statement' => UploadedFile::fake()->createWithContent('statement.csv', $csv),
            ]);
    }

    public function test_matches_by_amount_direction_and_cheque_number(): void
    {
        $csv = <<<'CSV'
HDFC BANK LTD
Statement of account
Date,Narration,Chq./Ref.No.,Value Dt,Withdrawal Amt.,Deposit Amt.,Closing Balance
03/06/26,NEFT CR ACME CUSTOMER,UTR99,03/06/26,,"20,000.00","20,000.00"
07/06/26,CHQ PAID 452,0000452,07/06/26,"8,000.00",,"12,000.00"
30/06/26,BANK CHARGES,,30/06/26,59.00,,"11,941.00"
CSV;

        $this->upload($csv)
            ->assertRedirect()
            ->assertSessionHas('success', '2 of 3 statement lines matched and cleared.')
            ->assertSessionHas('unmatched', fn ($lines) => count($lines) === 1 && $lines[0]['description'] === 'BANK CHARGES');

        $deposit = $this->bank->entries()->where('debit', '>', 0)->firstOrFail();
        $this->assertSame('2026-06-03', $deposit->bank_date->toDateString());
        $this->assertSame('UTR99', $deposit->instrument_no);

        // The cheque number picked 000452, not the first 8,000 cheque.
        $this->assertNull($this->bank->entries()->where('instrument_no', '000451')->value('bank_date'));
        $this->assertNotNull($this->bank->entries()->where('instrument_no', '000452')->value('bank_date'));
    }

    public function test_debit_credit_headers_and_iso_dates(): void
    {
        $csv = "Transaction Date,Description,Debit,Credit\n2026-06-02,Deposit,,20000\n";

        $this->upload($csv)->assertSessionHas('success', '1 of 1 statement lines matched and cleared.');
    }

    public function test_amount_far_from_book_date_is_not_matched(): void
    {
        $csv = "Date,Narration,Withdrawal,Deposit\n25/07/2026,Late deposit,,20000\n";

        $this->upload($csv)->assertSessionHas('success', '0 of 1 statement lines matched and cleared.');
    }

    public function test_file_without_header_is_rejected(): void
    {
        $this->upload("just,some,text\n1,2,3\n")->assertSessionHasErrors('statement');
    }

    public function test_page_shows_import_form(): void
    {
        $this->actingAs($this->company->user)
            ->get(route('companies.banking.reconciliation', [$this->company, 'account' => $this->bank->id]))
            ->assertOk()
            ->assertSee('Import bank statement (CSV)');
    }

    public function test_parser_handles_formats(): void
    {
        $lines = BankStatement::parse("\xEF\xBB\xBFTxn Date,Particulars,Dr,Cr,Ref No\n05-Jun-2026,X,\"1,23,456.50\",,ABC\n");

        $this->assertCount(1, $lines);
        $this->assertSame(123456.5, $lines[0]['withdrawal']);
        $this->assertSame('2026-06-05', $lines[0]['date']->toDateString());
        $this->assertSame('ABC', $lines[0]['reference']);
    }
}
