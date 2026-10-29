<?php

namespace Tests\Feature\Accounting;

use App\Models\Account;
use App\Models\AccountGroup;
use App\Models\AuditLog;
use App\Models\Voucher;
use App\Models\VoucherType;
use App\Services\Accounting\BillOutstanding;
use App\Services\Accounting\VoucherService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class VoucherEngineTest extends TestCase
{
    use RefreshDatabase;

    protected $owner;
    protected $company;
    protected VoucherService $vouchers;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->owner, $this->company] = $this->createCompanyWithOwner();
        $this->vouchers = app(VoucherService::class);
    }

    protected function type(string $base): int
    {
        return VoucherType::where('base_type', $base)->value('id');
    }

    protected function ledger(string $key): int
    {
        return Account::idFor($key);
    }

    protected function makeLedger(string $name, string $groupKey, array $extra = []): Account
    {
        return Account::create(array_merge([
            'name' => $name,
            'account_group_id' => AccountGroup::idFor($groupKey),
            'account_type' => match ($groupKey) {
                'bank_accounts' => 'bank', 'sundry_debtors', 'sundry_creditors' => 'party', default => 'general',
            },
            'opening_balance' => 0,
            'opening_balance_type' => 'Dr',
        ], $extra));
    }

    protected function voucher(string $base, array $lines, string $date = '2026-05-10', array $extra = []): Voucher
    {
        return $this->vouchers->create(array_merge([
            'voucher_type_id' => $this->type($base),
            'date' => $date,
            'narration' => 'Test',
            'entries' => $lines,
        ], $extra), $this->owner);
    }

    public function test_balanced_journal_posts_with_sequential_numbers(): void
    {
        $rent = $this->makeLedger('Rent', 'indirect_expenses');
        $landlord = $this->makeLedger('Landlord', 'sundry_creditors');

        $v1 = $this->voucher('journal', [
            ['account_id' => $rent->id, 'side' => 'Dr', 'amount' => '15000'],
            ['account_id' => $landlord->id, 'side' => 'Cr', 'amount' => '15000.00'],
        ]);
        $v2 = $this->voucher('journal', [
            ['account_id' => $rent->id, 'side' => 'Dr', 'amount' => '100.10'],
            ['account_id' => $landlord->id, 'side' => 'Cr', 'amount' => '100.1'],
        ]);

        $this->assertSame(Voucher::POSTED, $v1->status);
        $this->assertSame('JV/0001', $v1->voucher_number);
        $this->assertSame('JV/0002', $v2->voucher_number);
        $this->assertSame('15000.00', $v1->total);
        $this->assertCount(2, $v1->entries);
        $this->assertSame('15000.00', $v1->entries[0]->debit);
        $this->assertSame('0.00', $v1->entries[0]->credit);
    }

    public function test_unbalanced_voucher_is_rejected_and_nothing_is_saved(): void
    {
        $rent = $this->makeLedger('Rent', 'indirect_expenses');
        $landlord = $this->makeLedger('Landlord', 'sundry_creditors');

        try {
            $this->voucher('journal', [
                ['account_id' => $rent->id, 'side' => 'Dr', 'amount' => '1000'],
                ['account_id' => $landlord->id, 'side' => 'Cr', 'amount' => '999.99'],
            ]);
            $this->fail('Unbalanced voucher was accepted.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('must equal total credits', $e->errors()['entries'][0]);
        }

        $this->assertSame(0, Voucher::count());
        $this->assertSame(0, \App\Models\VoucherEntry::count());
    }

    public function test_rejects_bad_lines(): void
    {
        $rent = $this->makeLedger('Rent', 'indirect_expenses');

        $cases = [
            [['account_id' => $rent->id, 'side' => 'Dr', 'amount' => '100']], // single line
            [['account_id' => $rent->id, 'side' => 'Dr', 'amount' => '0'], ['account_id' => $this->ledger('capital'), 'side' => 'Cr', 'amount' => '0']],
            [['account_id' => $rent->id, 'side' => 'Dr', 'amount' => '-5'], ['account_id' => $this->ledger('capital'), 'side' => 'Cr', 'amount' => '-5']],
            [['account_id' => $rent->id, 'side' => 'Dr', 'amount' => '1.234'], ['account_id' => $this->ledger('capital'), 'side' => 'Cr', 'amount' => '1.234']],
            [['account_id' => 999999, 'side' => 'Dr', 'amount' => '5'], ['account_id' => $this->ledger('capital'), 'side' => 'Cr', 'amount' => '5']],
        ];

        foreach ($cases as $lines) {
            try {
                $this->voucher('journal', $lines);
                $this->fail('Invalid voucher accepted: '.json_encode($lines));
            } catch (ValidationException) {
                $this->assertTrue(true);
            }
        }
        $this->assertSame(0, Voucher::count());
    }

    public function test_voucher_type_rules(): void
    {
        $bank = $this->makeLedger('SBI', 'bank_accounts');
        $rent = $this->makeLedger('Rent', 'indirect_expenses');

        // Contra: cash to bank deposit is fine.
        $contra = $this->voucher('contra', [
            ['account_id' => $bank->id, 'side' => 'Dr', 'amount' => '500'],
            ['account_id' => $this->ledger('cash'), 'side' => 'Cr', 'amount' => '500'],
        ]);
        $this->assertSame('CT/0001', $contra->voucher_number);

        $invalid = [
            ['contra', [['account_id' => $rent->id, 'side' => 'Dr', 'amount' => '5'], ['account_id' => $bank->id, 'side' => 'Cr', 'amount' => '5']]],
            ['payment', [['account_id' => $rent->id, 'side' => 'Dr', 'amount' => '5'], ['account_id' => $this->ledger('capital'), 'side' => 'Cr', 'amount' => '5']]],
            ['receipt', [['account_id' => $this->ledger('capital'), 'side' => 'Dr', 'amount' => '5'], ['account_id' => $rent->id, 'side' => 'Cr', 'amount' => '5']]],
            ['journal', [['account_id' => $rent->id, 'side' => 'Dr', 'amount' => '5'], ['account_id' => $this->ledger('cash'), 'side' => 'Cr', 'amount' => '5']]],
        ];

        foreach ($invalid as [$base, $lines]) {
            try {
                $this->voucher($base, $lines);
                $this->fail("{$base} rule not enforced");
            } catch (ValidationException) {
                $this->assertTrue(true);
            }
        }

        $payment = $this->voucher('payment', [
            ['account_id' => $rent->id, 'side' => 'Dr', 'amount' => '5000'],
            ['account_id' => $bank->id, 'side' => 'Cr', 'amount' => '5000'],
        ]);
        $this->assertSame('PY/0001', $payment->voucher_number);
    }

    public function test_dates_outside_year_locked_or_future_are_rejected(): void
    {
        $rent = $this->makeLedger('Rent', 'indirect_expenses');
        $lines = [
            ['account_id' => $rent->id, 'side' => 'Dr', 'amount' => '10'],
            ['account_id' => $this->ledger('capital'), 'side' => 'Cr', 'amount' => '10'],
        ];

        foreach (['2025-12-31', now()->addDays(3)->toDateString()] as $date) {
            try {
                $this->voucher('journal', $lines, $date);
                $this->fail("Date {$date} accepted");
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('date', $e->errors());
            }
        }

        $year = $this->company->currentFinancialYear();
        $year->periods()->whereDate('start_date', '2026-05-01')->update(['is_locked' => true]);

        try {
            $this->voucher('journal', $lines, '2026-05-15');
            $this->fail('Locked period accepted');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('locked', $e->errors()['date'][0]);
        }

        // A draft may still be saved in a locked period; it cannot be posted.
        $draft = $this->vouchers->create(['voucher_type_id' => $this->type('journal'), 'date' => '2026-05-15', 'entries' => $lines], $this->owner, Voucher::DRAFT);
        $this->assertNull($draft->voucher_number);
        $this->expectException(ValidationException::class);
        $this->vouchers->post($draft, $this->owner);
    }

    public function test_draft_then_post_assigns_number_on_posting(): void
    {
        $rent = $this->makeLedger('Rent', 'indirect_expenses');

        $draft = $this->vouchers->create([
            'voucher_type_id' => $this->type('journal'),
            'date' => '2026-06-01',
            'entries' => [['account_id' => $rent->id, 'side' => 'Dr', 'amount' => '10']], // unbalanced draft is allowed
        ], $this->owner, Voucher::DRAFT);

        $this->assertSame(Voucher::DRAFT, $draft->status);
        $this->assertNull($draft->voucher_number);

        try {
            $this->vouchers->post($draft, $this->owner);
            $this->fail('Unbalanced draft posted');
        } catch (ValidationException) {
        }

        $data = $this->vouchers->toInput($draft);
        $data['entries'][] = ['account_id' => $this->ledger('capital'), 'side' => 'Cr', 'amount' => '10'];
        $posted = $this->vouchers->update($draft, $data, $this->owner, Voucher::POSTED);

        $this->assertSame(Voucher::POSTED, $posted->status);
        $this->assertSame('JV/0001', $posted->voucher_number);
        $this->assertNotNull($posted->posted_at);
    }

    public function test_posted_voucher_cannot_be_deleted_but_can_be_cancelled_and_reversed(): void
    {
        $rent = $this->makeLedger('Rent', 'indirect_expenses');
        $bank = $this->makeLedger('SBI', 'bank_accounts');
        $lines = [
            ['account_id' => $rent->id, 'side' => 'Dr', 'amount' => '700'],
            ['account_id' => $bank->id, 'side' => 'Cr', 'amount' => '700'],
        ];

        $v = $this->voucher('payment', $lines);
        try {
            $this->vouchers->delete($v);
            $this->fail('Posted voucher deleted');
        } catch (ValidationException) {
        }

        $reversal = $this->vouchers->reverse($v, $this->owner, '2026-05-20');
        $this->assertSame($v->id, $reversal->reversal_of_id);
        $this->assertSame('JV/0001', $reversal->voucher_number);
        $this->assertSame('700.00', $reversal->entries->firstWhere('account_id', $bank->id)->debit);

        // Reversed vouchers cannot be cancelled while the reversal stands.
        try {
            $this->vouchers->cancel($v, $this->owner, 'Wrong entry');
            $this->fail('Cancelled a reversed voucher');
        } catch (ValidationException) {
        }

        $v2 = $this->voucher('payment', $lines);
        $cancelled = $this->vouchers->cancel($v2, $this->owner, 'Duplicate entry');
        $this->assertSame(Voucher::CANCELLED, $cancelled->status);
        $this->assertSame('PY/0002', $cancelled->voucher_number); // number is kept
        $this->assertSame('Duplicate entry', $cancelled->cancellation_reason);
    }

    public function test_altering_posted_voucher_keeps_revision_history(): void
    {
        $rent = $this->makeLedger('Rent', 'indirect_expenses');
        $v = $this->voucher('journal', [
            ['account_id' => $rent->id, 'side' => 'Dr', 'amount' => '100'],
            ['account_id' => $this->ledger('capital'), 'side' => 'Cr', 'amount' => '100'],
        ]);

        $data = $this->vouchers->toInput($v);
        $data['entries'][0]['amount'] = '150';
        $data['entries'][1]['amount'] = '150';
        $altered = $this->vouchers->update($v, $data, $this->owner, Voucher::POSTED, 'Amount corrected');

        $this->assertSame('150.00', $altered->total);
        $this->assertSame('JV/0001', $altered->voucher_number);
        $this->assertSame(1, $altered->revision);
        $revision = $altered->revisions()->first();
        $this->assertSame('100.00', $revision->snapshot['total']);
        $this->assertSame('Amount corrected', $revision->reason);
        $this->assertTrue(AuditLog::where('auditable_type', Voucher::class)->where('event', 'updated')->exists());
    }

    public function test_bill_wise_allocations_and_outstanding(): void
    {
        $customer = $this->makeLedger('Mehta Stores', 'sundry_debtors', ['maintain_bill_wise' => true]);
        $bank = $this->makeLedger('SBI', 'bank_accounts');
        $outstanding = app(BillOutstanding::class);

        // Sale on credit opens bill INV-1 for 10,000.
        $this->voucher('sales', [
            ['account_id' => $customer->id, 'side' => 'Dr', 'amount' => '10000', 'bills' => [
                ['bill_type' => 'new_ref', 'bill_ref' => 'INV-1', 'amount' => '10000', 'due_date' => '2026-06-10'],
            ]],
            ['account_id' => $this->ledger('sales'), 'side' => 'Cr', 'amount' => '10000'],
        ]);

        $this->assertSame(1000000, $outstanding->find($customer->id, 'INV-1')->pending);

        // Over-settlement is refused.
        try {
            $this->voucher('receipt', [
                ['account_id' => $bank->id, 'side' => 'Dr', 'amount' => '12000'],
                ['account_id' => $customer->id, 'side' => 'Cr', 'amount' => '12000', 'bills' => [
                    ['bill_type' => 'agst_ref', 'bill_ref' => 'INV-1', 'amount' => '12000'],
                ]],
            ]);
            $this->fail('Over-allocation accepted');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('pending', implode(' ', array_merge(...array_values($e->errors()))));
        }

        // Allocations must add up to the line amount.
        try {
            $this->voucher('receipt', [
                ['account_id' => $bank->id, 'side' => 'Dr', 'amount' => '5000'],
                ['account_id' => $customer->id, 'side' => 'Cr', 'amount' => '5000', 'bills' => [
                    ['bill_type' => 'agst_ref', 'bill_ref' => 'INV-1', 'amount' => '4000'],
                ]],
            ]);
            $this->fail('Mismatched allocation accepted');
        } catch (ValidationException) {
        }

        // Duplicate new reference is refused.
        try {
            $this->voucher('sales', [
                ['account_id' => $customer->id, 'side' => 'Dr', 'amount' => '10', 'bills' => [['bill_type' => 'new_ref', 'bill_ref' => 'inv-1', 'amount' => '10']]],
                ['account_id' => $this->ledger('sales'), 'side' => 'Cr', 'amount' => '10'],
            ]);
            $this->fail('Duplicate bill accepted');
        } catch (ValidationException) {
        }

        // Part payment of 6,000 leaves 4,000 pending.
        $receipt = $this->voucher('receipt', [
            ['account_id' => $bank->id, 'side' => 'Dr', 'amount' => '6000'],
            ['account_id' => $customer->id, 'side' => 'Cr', 'amount' => '6000', 'bills' => [
                ['bill_type' => 'agst_ref', 'bill_ref' => 'INV-1', 'amount' => '6000'],
            ]],
        ], '2026-06-01');
        $this->assertSame(400000, $outstanding->find($customer->id, 'INV-1')->pending);

        // The sale cannot be cancelled while the receipt settles its bill.
        $sale = Voucher::where('voucher_type_id', $this->type('sales'))->first();
        try {
            $this->vouchers->cancel($sale, $this->owner, 'test');
            $this->fail('Cancelled a settled bill');
        } catch (ValidationException) {
        }

        // Cancelling the receipt re-opens the full bill.
        $this->vouchers->cancel($receipt, $this->owner, 'Cheque bounced');
        $this->assertSame(1000000, $outstanding->find($customer->id, 'INV-1')->pending);

        // A party line without allocations goes on account automatically.
        $onAccount = $this->voucher('receipt', [
            ['account_id' => $bank->id, 'side' => 'Dr', 'amount' => '250'],
            ['account_id' => $customer->id, 'side' => 'Cr', 'amount' => '250'],
        ]);
        $this->assertSame('on_account', $onAccount->entries->firstWhere('account_id', $customer->id)->billAllocations->first()->bill_type);
        $this->assertSame($customer->id, $onAccount->party_account_id);
    }

    public function test_altering_a_settled_bill_below_the_settled_amount_is_refused(): void
    {
        $customer = $this->makeLedger('Mehta Stores', 'sundry_debtors', ['maintain_bill_wise' => true]);
        $bank = $this->makeLedger('SBI', 'bank_accounts');

        $sale = $this->voucher('sales', [
            ['account_id' => $customer->id, 'side' => 'Dr', 'amount' => '1000', 'bills' => [['bill_type' => 'new_ref', 'bill_ref' => 'B1', 'amount' => '1000']]],
            ['account_id' => $this->ledger('sales'), 'side' => 'Cr', 'amount' => '1000'],
        ]);
        $this->voucher('receipt', [
            ['account_id' => $bank->id, 'side' => 'Dr', 'amount' => '800'],
            ['account_id' => $customer->id, 'side' => 'Cr', 'amount' => '800', 'bills' => [['bill_type' => 'agst_ref', 'bill_ref' => 'B1', 'amount' => '800']]],
        ]);

        $data = $this->vouchers->toInput($sale);
        $data['entries'][0]['amount'] = '500';
        $data['entries'][0]['bills'][0]['amount'] = '500';
        $data['entries'][1]['amount'] = '500';

        $this->expectException(ValidationException::class);
        $this->vouchers->update($sale, $data, $this->owner, Voucher::POSTED);
    }

    public function test_manual_numbering_rejects_duplicates(): void
    {
        $type = VoucherType::create(['name' => 'Manual Journal', 'base_type' => 'journal', 'numbering' => 'manual']);
        $rent = $this->makeLedger('Rent', 'indirect_expenses');
        $lines = [
            ['account_id' => $rent->id, 'side' => 'Dr', 'amount' => '10'],
            ['account_id' => $this->ledger('capital'), 'side' => 'Cr', 'amount' => '10'],
        ];

        $this->vouchers->create(['voucher_type_id' => $type->id, 'date' => '2026-05-01', 'voucher_number' => 'M-1', 'entries' => $lines], $this->owner);

        try {
            $this->vouchers->create(['voucher_type_id' => $type->id, 'date' => '2026-05-02', 'voucher_number' => 'M-1', 'entries' => $lines], $this->owner);
            $this->fail('Duplicate manual number accepted');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('voucher_number', $e->errors());
        }
    }

    public function test_database_rejects_duplicate_numbers_even_without_validation(): void
    {
        $rent = $this->makeLedger('Rent', 'indirect_expenses');
        $v = $this->voucher('journal', [
            ['account_id' => $rent->id, 'side' => 'Dr', 'amount' => '10'],
            ['account_id' => $this->ledger('capital'), 'side' => 'Cr', 'amount' => '10'],
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);
        Voucher::create($v->only(['company_id', 'voucher_type_id', 'financial_year_id', 'voucher_number', 'date', 'status']));
    }

    public function test_numbering_continues_after_cancellation_and_restarts_each_year(): void
    {
        $this->company->update(['settings' => ['allow_future_dated_vouchers' => true]]);
        \App\Models\FinancialYear::open($this->company, '2027-04-01', makeCurrent: false);
        $rent = $this->makeLedger('Rent', 'indirect_expenses');
        $lines = [
            ['account_id' => $rent->id, 'side' => 'Dr', 'amount' => '10'],
            ['account_id' => $this->ledger('capital'), 'side' => 'Cr', 'amount' => '10'],
        ];

        $a = $this->voucher('journal', $lines, '2026-05-01');
        $this->vouchers->cancel($a, $this->owner, 'x');
        $b = $this->voucher('journal', $lines, '2026-05-02');
        $next = $this->voucher('journal', $lines, '2027-04-05');

        $this->assertSame('JV/0002', $b->voucher_number);
        $this->assertSame('JV/0001', $next->voucher_number);
    }

    public function test_maker_checker_approval(): void
    {
        $this->company->update(['settings' => ['require_voucher_approval' => true]]);
        $clerk = $this->addMember($this->company, 'user');
        $rent = $this->makeLedger('Rent', 'indirect_expenses');

        $status = $this->vouchers->resolveStatus($clerk, 'post');
        $this->assertSame(Voucher::PENDING, $status);

        $v = $this->vouchers->create([
            'voucher_type_id' => $this->type('journal'),
            'date' => '2026-05-01',
            'entries' => [
                ['account_id' => $rent->id, 'side' => 'Dr', 'amount' => '10'],
                ['account_id' => $this->ledger('capital'), 'side' => 'Cr', 'amount' => '10'],
            ],
        ], $clerk, $status);
        $this->assertSame(Voucher::PENDING, $v->status);
        $this->assertNull($v->voucher_number);

        $approved = $this->vouchers->post($v, $this->owner);
        $this->assertSame(Voucher::POSTED, $approved->status);
        $this->assertSame($this->owner->id, $approved->approved_by);
        $this->assertSame('JV/0001', $approved->voucher_number);
    }
}
