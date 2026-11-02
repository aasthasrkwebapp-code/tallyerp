<?php

namespace Tests\Feature\Banking;

use App\Models\Account;
use App\Models\AccountGroup;
use App\Models\BankStatementLine;
use App\Models\Voucher;
use App\Models\VoucherType;
use App\Services\Accounting\VoucherService;
use App\Services\Banking\BankReconciliation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Bank: opening 10,000. Receipt 5,000 on 2 Sep (cheque 111), payment 2,000 on 5 Sep (cheque 222),
 * payment 750 on 20 Sep. Books balance 12,250.
 */
class BankReconciliationTest extends TestCase
{
    use RefreshDatabase;

    protected $owner;
    protected Account $bank;
    protected array $v = [];

    protected function setUp(): void
    {
        parent::setUp();
        [$this->owner] = $this->createCompanyWithOwner();
        $this->bank = Account::create(['name' => 'HDFC', 'account_group_id' => AccountGroup::idFor('bank_accounts'), 'account_type' => 'bank', 'opening_balance' => 10000]);
        $rent = Account::create(['name' => 'Rent', 'account_group_id' => AccountGroup::idFor('indirect_expenses'), 'account_type' => 'general']);

        $post = fn ($base, $date, $dr, $cr, $amount, $ref = null) => app(VoucherService::class)->create([
            'voucher_type_id' => VoucherType::where('base_type', $base)->value('id'), 'date' => $date, 'reference' => $ref,
            'entries' => [['account_id' => $dr, 'side' => 'Dr', 'amount' => $amount], ['account_id' => $cr, 'side' => 'Cr', 'amount' => $amount]],
        ], $this->owner);

        $this->v['receipt'] = $post('receipt', '2026-09-02', $this->bank->id, Account::idFor('interest_received'), '5000', '111');
        $this->v['payment'] = $post('payment', '2026-09-05', $rent->id, $this->bank->id, '2000', '222');
        $this->v['small'] = $post('payment', '2026-09-20', $rent->id, $this->bank->id, '750');
    }

    protected function entry(string $key)
    {
        return $this->v[$key]->entries()->where('account_id', $this->bank->id)->first();
    }

    protected function service(): BankReconciliation
    {
        return app(BankReconciliation::class);
    }

    public function test_summary_explains_the_difference(): void
    {
        $this->service()->setBankDates($this->bank, [$this->entry('receipt')->id => '2026-09-04', $this->entry('payment')->id => '2026-10-02'], $this->owner);

        $s = $this->service()->summary($this->bank, '2026-09-30');
        $this->assertSame(1225000, $s['book']);
        $this->assertSame(0, $s['uncleared_deposits']);
        $this->assertSame(275000, $s['uncleared_withdrawals']); // 2,000 cleared in October + 750
        $this->assertSame(1500000, $s['bank']);

        $this->assertCount(2, $this->service()->entries($this->bank, null, '2026-09-30', true));
        $this->assertSame(1300000, $this->service()->summary($this->bank, '2026-10-31')['bank']); // only the 750 is still uncleared
    }

    public function test_bank_date_cannot_precede_the_voucher(): void
    {
        $this->expectException(ValidationException::class);
        $this->service()->setBankDates($this->bank, [$this->entry('payment')->id => '2026-09-01'], $this->owner);
    }

    public function test_alteration_keeps_the_bank_date_and_cancel_clears_it(): void
    {
        $this->service()->setBankDates($this->bank, [$this->entry('receipt')->id => '2026-09-04'], $this->owner);

        $input = app(VoucherService::class)->toInput($this->v['receipt']);
        $input['narration'] = 'Interest for August';
        app(VoucherService::class)->update($this->v['receipt'], $input, $this->owner, Voucher::POSTED, 'narration');
        $this->assertSame('2026-09-04', $this->entry('receipt')->bank_date->toDateString());

        app(VoucherService::class)->cancel($this->v['receipt']->fresh(), $this->owner, 'wrong');
        $this->assertNull($this->entry('receipt')->bank_date);
    }

    public function test_statement_import_and_auto_match(): void
    {
        $csv = "Txn Date,Description,Ref No,Withdrawal,Deposit,Balance\n"
            ."04/09/2026,CHQ DEP 111,111,,\"5,000.00\",15000\n"
            ."07-09-2026,CHQ 222 RENT,222,2000.00,,13000\n"
            ."30/09/2026,BANK CHARGES,,118.00,,12882\n";

        $this->assertSame(3, $this->service()->import($this->bank, $csv, $this->owner));
        $this->assertSame(2, $this->service()->autoMatch($this->bank, $this->owner));

        $this->assertSame('2026-09-04', $this->entry('receipt')->bank_date->toDateString());
        $this->assertSame('2026-09-07', $this->entry('payment')->bank_date->toDateString());
        $this->assertNull($this->entry('small')->bank_date);
        $this->assertSame(1, BankStatementLine::whereNull('voucher_entry_id')->count()); // bank charges: enter a voucher

        $line = BankStatementLine::whereNotNull('voucher_entry_id')->first();
        $this->service()->unmatch($line, $this->owner);
        $this->assertNull($line->fresh()->voucher_entry_id);
    }

    public function test_ambiguous_lines_are_left_for_manual_matching(): void
    {
        app(VoucherService::class)->create([
            'voucher_type_id' => VoucherType::where('base_type', 'payment')->value('id'), 'date' => '2026-09-21',
            'entries' => [['account_id' => Account::idFor('bank_charges'), 'side' => 'Dr', 'amount' => '750'], ['account_id' => $this->bank->id, 'side' => 'Cr', 'amount' => '750']],
        ], $this->owner);

        $this->service()->import($this->bank, "Date,Description,Amount\n2026-09-22,NEFT,-750", $this->owner);
        $this->assertSame(0, $this->service()->autoMatch($this->bank, $this->owner));

        $line = BankStatementLine::first();
        $this->assertCount(2, $this->service()->candidates($this->bank, $line));
        $this->service()->match($line, $this->entry('small'), $this->owner);
        $this->assertSame('2026-09-22', $this->entry('small')->bank_date->toDateString());

        // A deposit line cannot be matched to a payment.
        $this->service()->import($this->bank, "Date,Description,Amount\n2026-09-22,X,2000", $this->owner);
        $this->expectException(ValidationException::class);
        $this->service()->match(BankStatementLine::whereNull('voucher_entry_id')->first(), $this->entry('payment'), $this->owner);
    }

    public function test_bad_files_are_rejected(): void
    {
        foreach (["Foo,Bar\n1,2", "Date,Withdrawal,Deposit\n31/31/2026,1,", "Date,Withdrawal,Deposit\n2026-09-01,10,20"] as $csv) {
            try {
                $this->service()->import($this->bank, $csv, $this->owner);
                $this->fail('Accepted: '.$csv);
            } catch (ValidationException) {
            }
        }
        $this->assertSame(0, BankStatementLine::count());
    }

    public function test_screens(): void
    {
        $this->actingAs($this->owner);
        $this->get(route('bank-reconciliation.index', ['to' => '2026-09-30']))->assertOk()->assertSee('HDFC')->assertSee('12,250.00 Dr');

        $this->post(route('bank-reconciliation.update', $this->bank), ['dates' => [$this->entry('receipt')->id => '2026-09-03']])->assertSessionHas('success');
        $this->assertSame('2026-09-03', $this->entry('receipt')->bank_date->toDateString());

        $file = UploadedFile::fake()->createWithContent('stmt.csv', "Date,Description,Withdrawal,Deposit\n2026-09-06,CHQ 222,2000,\n");
        $this->post(route('bank-reconciliation.import', $this->bank), ['statement' => $file])->assertSessionHas('success');
        $this->assertSame('2026-09-06', $this->entry('payment')->bank_date->toDateString());

        $this->get(route('bank-reconciliation.index', ['statement' => 'all']))->assertOk()->assertSee('CHQ 222');

        $viewer = $this->addMember(\App\Models\Company::first(), 'viewer');
        $this->actingAs($viewer)->get(route('bank-reconciliation.index'))->assertOk();
        $this->actingAs($viewer)->post(route('bank-reconciliation.update', $this->bank), ['dates' => [$this->entry('small')->id => '2026-09-21']])->assertForbidden();
    }
}
