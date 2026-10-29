<?php

namespace Tests\Feature\Banking;

use App\Models\Account;
use App\Models\AccountGroup;
use App\Models\ChequeBook;
use App\Models\SpoiltCheque;
use App\Models\Voucher;
use App\Models\VoucherType;
use App\Services\Accounting\VoucherService;
use App\Services\Banking\ChequeRegister;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ChequeRegisterTest extends TestCase
{
    use RefreshDatabase;

    protected $owner;
    protected $company;
    protected Account $bank;
    protected Account $supplier;
    protected Account $customer;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-20 10:00:00');
        [$this->owner, $this->company] = $this->createCompanyWithOwner();
        $this->bank = Account::create(['name' => 'HDFC', 'account_group_id' => AccountGroup::idFor('bank_accounts'), 'account_type' => 'bank', 'opening_balance' => 100000]);
        $this->supplier = Account::create(['name' => 'Delhi Supplies', 'account_group_id' => AccountGroup::idFor('sundry_creditors'), 'account_type' => 'party']);
        $this->customer = Account::create(['name' => 'Mumbai Retail', 'account_group_id' => AccountGroup::idFor('sundry_debtors'), 'account_type' => 'party']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    protected function payment(string $number, string $amount = '5000', string $date = '2026-10-10', array $extra = []): Voucher
    {
        return app(VoucherService::class)->create(array_merge([
            'voucher_type_id' => VoucherType::where('base_type', 'payment')->value('id'),
            'date' => $date,
            'instrument_type' => 'cheque',
            'instrument_number' => $number,
            'entries' => [
                ['account_id' => $this->supplier->id, 'side' => 'Dr', 'amount' => $amount],
                ['account_id' => $this->bank->id, 'side' => 'Cr', 'amount' => $amount],
            ],
        ], $extra), $this->owner);
    }

    public function test_issued_cheque_is_stored_and_defaults_payee_and_date(): void
    {
        $v = $this->payment('451');
        $this->assertSame('000451', $v->instrument_number);
        $this->assertSame('issued', $v->instrument_direction);
        $this->assertSame($this->bank->id, $v->instrument_account_id);
        $this->assertSame('Delhi Supplies', $v->payee_name);
        $this->assertSame('2026-10-10', $v->instrument_date->toDateString());

        $this->actingAs($this->owner)->get(route('vouchers.show', $v))->assertOk()->assertSee('000451')->assertSee('Print cheque');
        $this->actingAs($this->owner)->get(route('cheques.print', $v))->assertOk()
            ->assertSee('10102026')->assertSee('Delhi Supplies')->assertSee('Five Thousand Only')->assertSee('**5,000.00/-');
    }

    public function test_cheque_books_limit_numbers_and_block_reuse_and_spoilt_leaves(): void
    {
        $this->actingAs($this->owner)->post(route('cheque-books.store'), ['account_id' => $this->bank->id, 'from_number' => '100001', 'to_number' => '100025'])->assertRedirect();
        $book = ChequeBook::sole();
        $this->assertSame('100001–100025', $book->name);

        $this->expectValidation(fn () => $this->payment('200001'), 'not in any cheque book');
        $this->payment('100001');
        $this->expectValidation(fn () => $this->payment('100001'), 'already used');

        $this->actingAs($this->owner)->post(route('cheque-books.spoil', $book), ['number' => '100002', 'date' => '2026-10-11', 'reason' => 'Torn'])->assertSessionHas('success');
        $this->actingAs($this->owner)->post(route('cheque-books.spoil', $book), ['number' => '100001', 'date' => '2026-10-11', 'reason' => 'x'])->assertSessionHasErrors('number');
        $this->expectValidation(fn () => $this->payment('100002'), 'spoilt');

        $usage = app(ChequeRegister::class)->usage($book);
        $this->assertSame('100003', $usage['unused'][0]);
        $this->assertSame('100003', app(ChequeRegister::class)->nextNumber($this->bank->id));

        // Overlapping books are refused; a used book cannot be deleted.
        $this->actingAs($this->owner)->post(route('cheque-books.store'), ['account_id' => $this->bank->id, 'from_number' => '100020', 'to_number' => '100030'])->assertSessionHasErrors('from_number');
        $this->actingAs($this->owner)->delete(route('cheque-books.destroy', $book))->assertSessionHas('error');

        // A cancelled voucher frees its leaf.
        $first = Voucher::where('instrument_number', '100001')->sole();
        app(VoucherService::class)->cancel($first, $this->owner, 'Wrong amount');
        $this->payment('100001', '4500');
        $this->assertSame(2, Voucher::where('instrument_number', '100001')->count());
    }

    public function test_register_statuses(): void
    {
        $cleared = $this->payment('11', '1000', '2026-10-01');
        $pending = $this->payment('12', '2000', '2026-10-05');
        $stale = $this->payment('13', '3000', '2026-06-01');
        $post = $this->payment('14', '4000', '2026-10-15', ['instrument_date' => '2026-11-15']);
        $cancelled = $this->payment('15', '500', '2026-10-06');
        app(VoucherService::class)->cancel($cancelled, $this->owner, 'Stopped');
        $received = app(VoucherService::class)->create([
            'voucher_type_id' => VoucherType::where('base_type', 'receipt')->value('id'), 'date' => '2026-10-07',
            'instrument_type' => 'cheque', 'instrument_number' => '987654', 'payee_name' => 'Mumbai Retail',
            'entries' => [
                ['account_id' => $this->bank->id, 'side' => 'Dr', 'amount' => '7000'],
                ['account_id' => $this->customer->id, 'side' => 'Cr', 'amount' => '7000'],
            ],
        ], $this->owner);
        $cleared->entries()->where('account_id', $this->bank->id)->update(['bank_date' => '2026-10-03']);

        $rows = app(ChequeRegister::class)->rows([])->keyBy('number');
        $this->assertSame('cleared', $rows['000011']->status);
        $this->assertSame('2026-10-03', $rows['000011']->bank_date->toDateString());
        $this->assertSame('pending', $rows['000012']->status);
        $this->assertSame(15, $rows['000012']->days);
        $this->assertSame('stale', $rows['000013']->status);
        $this->assertSame('post_dated', $rows['000014']->status);
        $this->assertSame('cancelled', $rows['000015']->status);
        $this->assertSame('received', $rows['987654']->direction);
        $this->assertSame(7000.0, $rows['987654']->amount);

        $this->assertCount(1, app(ChequeRegister::class)->rows(['direction' => 'received']));
        $this->assertCount(1, app(ChequeRegister::class)->rows(['status' => 'stale']));

        $this->actingAs($this->owner)->get(route('cheques.index'))->assertOk()->assertSee('000012')->assertSee('Stale (over 3 months)');
        $this->actingAs($this->owner)->get(route('cheques.index', ['export' => 'csv']))->assertOk();
    }

    public function test_reversal_marks_the_cheque_reversed_and_does_not_copy_it(): void
    {
        $v = $this->payment('21');
        $reversal = app(VoucherService::class)->reverse($v, $this->owner, '2026-10-12', 'Cheque dishonoured');
        $this->assertNull($reversal->instrument_type);
        $this->assertSame('reversed', app(ChequeRegister::class)->rows([])->firstWhere('number', '000021')->status);
    }

    public function test_instrument_needs_a_bank_line_and_a_valid_number(): void
    {
        $journal = VoucherType::where('base_type', 'journal')->value('id');
        $expense = Account::create(['name' => 'Rent', 'account_group_id' => AccountGroup::idFor('indirect_expenses'), 'account_type' => 'general']);
        $this->expectValidation(fn () => app(VoucherService::class)->create([
            'voucher_type_id' => $journal, 'date' => '2026-10-10', 'instrument_type' => 'cheque', 'instrument_number' => '1',
            'entries' => [['account_id' => $expense->id, 'side' => 'Dr', 'amount' => '10'], ['account_id' => $this->supplier->id, 'side' => 'Cr', 'amount' => '10']],
        ], $this->owner), 'needs a bank ledger line');
        $this->expectValidation(fn () => $this->payment('12AB'), 'digits only');

        // NEFT needs no number; the UTR is kept when given.
        $v = $this->payment('', '100', '2026-10-10', ['instrument_type' => 'neft', 'instrument_number' => 'HDFCN52026101012345']);
        $this->assertSame('HDFCN52026101012345', $v->instrument_number);
        $this->assertNull($v->instrument_date);
    }

    public function test_print_layout_and_permissions(): void
    {
        $book = ChequeBook::create(['account_id' => $this->bank->id, 'name' => 'B1', 'from_number' => 1, 'to_number' => 50]);
        $v = $this->payment('5');
        $this->actingAs($this->owner)->put(route('cheque-books.update', $book), [
            'account_id' => $this->bank->id, 'from_number' => 1, 'to_number' => 50, 'name' => 'B1', 'is_active' => '1',
            'layout' => ['date_x' => '150', 'payee_y' => '25'],
        ])->assertRedirect();
        $this->assertEquals(150, $book->fresh()->printLayout()['date_x']);
        $this->assertFalse($book->fresh()->printLayout()['ac_payee']);
        $this->actingAs($this->owner)->get(route('cheques.print', $v))->assertSee('left: 150mm', false)->assertDontSee('A/c Payee');

        $viewer = $this->addMember($this->company, 'viewer');
        $this->actingAs($viewer)->get(route('cheques.index'))->assertOk();
        $this->actingAs($viewer)->post(route('cheque-books.spoil', $book), ['number' => '9', 'date' => '2026-10-10', 'reason' => 'x'])->assertForbidden();
        $this->assertSame(0, SpoiltCheque::count());
    }

    public function test_batch_printing_marks_cheques_printed(): void
    {
        $a = $this->payment('12', '1500');
        $b = $this->payment('11', '2500');
        $cancelled = $this->payment('13');
        app(VoucherService::class)->cancel($cancelled, $this->owner, 'Wrong party');
        $received = app(VoucherService::class)->create([
            'voucher_type_id' => VoucherType::where('base_type', 'receipt')->value('id'), 'date' => '2026-10-10',
            'instrument_type' => 'cheque', 'instrument_number' => '777',
            'entries' => [['account_id' => $this->bank->id, 'side' => 'Dr', 'amount' => '100'], ['account_id' => $this->customer->id, 'side' => 'Cr', 'amount' => '100']],
        ], $this->owner);

        $this->actingAs($this->owner)->get(route('cheques.index', ['printed' => 'no']))->assertOk()
            ->assertSee('Print ticked cheques')->assertSee('value="'.$a->id.'"', false)->assertDontSee('value="'.$cancelled->id.'"', false)->assertDontSee('000777');

        // In cheque number order; the cancelled and the received cheque are left out.
        $page = $this->get(route('cheques.print-batch', ['ids' => [$a->id, $b->id, $cancelled->id, $received->id]]))->assertOk()
            ->assertSee('2 cheques, one per page')->assertSee('One Thousand Five Hundred Only')->assertSee('Two Thousand Five Hundred Only')->assertDontSee('Printed before');
        $this->assertLessThan(strpos($page->getContent(), '**1,500.00/-'), strpos($page->getContent(), '**2,500.00/-'));
        $this->assertSame(1, $a->fresh()->cheque_print_count);
        $this->assertNotNull($a->fresh()->cheque_printed_at);
        $this->assertSame(0, $cancelled->fresh()->cheque_print_count);

        // Reprinting is allowed and flagged.
        $this->get(route('cheques.print', $a))->assertSee('Printed before: 000012');
        $this->assertSame(2, $a->fresh()->cheque_print_count);
        $this->get(route('cheques.index', ['printed' => 'yes']))->assertSee('000012')->assertSee('(2×)')->assertDontSee('000777');
        $this->get(route('cheques.index', ['printed' => 'no']))->assertDontSee('000012');
        $this->assertStringContainsString('Printed', $this->get(route('cheques.index', ['export' => 'csv']))->streamedContent());

        $this->from(route('cheques.index'))->get(route('cheques.print-batch', ['ids' => [$received->id]]))->assertRedirect(route('cheques.index'))->assertSessionHas('error');
        $this->get(route('cheques.print-batch'))->assertSessionHasErrors('ids');

        // Cheques of books with different sizes are printed separately.
        $sbi = Account::create(['name' => 'SBI', 'account_group_id' => AccountGroup::idFor('bank_accounts'), 'account_type' => 'bank', 'opening_balance' => 100000]);
        ChequeBook::create(['account_id' => $sbi->id, 'name' => 'S1', 'from_number' => 1, 'to_number' => 50, 'layout' => ['width' => 180] + ChequeBook::DEFAULT_LAYOUT]);
        $c = app(VoucherService::class)->create([
            'voucher_type_id' => VoucherType::where('base_type', 'payment')->value('id'), 'date' => '2026-10-10', 'instrument_type' => 'cheque', 'instrument_number' => '3',
            'entries' => [['account_id' => $this->supplier->id, 'side' => 'Dr', 'amount' => '10'], ['account_id' => $sbi->id, 'side' => 'Cr', 'amount' => '10']],
        ], $this->owner);
        $this->from(route('cheques.index'))->get(route('cheques.print-batch', ['ids' => [$a->id, $c->id]]))->assertRedirect()->assertSessionHas('error', fn ($m) => str_contains($m, 'different cheque sizes'));
        $this->assertSame(0, $c->fresh()->cheque_print_count);
    }

    protected function expectValidation(callable $fn, string $needle): void
    {
        try {
            $fn();
            $this->fail("Expected a validation error containing \"{$needle}\".");
        } catch (ValidationException $e) {
            $this->assertStringContainsString($needle, collect($e->errors())->flatten()->implode(' '));
        }
    }
}
