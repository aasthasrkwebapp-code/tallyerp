<?php

namespace Tests\Feature\Accounting;

use App\Models\Account;
use App\Models\AccountGroup;
use App\Models\Party;
use App\Models\VoucherType;
use App\Services\Accounting\OpeningBills;
use App\Services\Accounting\VoucherService;
use App\Services\Reports\Outstanding;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class OpeningBillsTest extends TestCase
{
    use RefreshDatabase;

    protected $owner;
    protected $company;
    protected Account $customer;
    protected Account $bank;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-06-15 10:00:00');
        [$this->owner, $this->company] = $this->createCompanyWithOwner();
        // Owes 50,000 when the books begin on 1 April 2026.
        $this->customer = Account::create(['name' => 'Mehta Stores', 'account_group_id' => AccountGroup::idFor('sundry_debtors'), 'account_type' => 'party',
            'maintain_bill_wise' => true, 'opening_balance' => '50000', 'opening_balance_type' => 'Dr']);
        Party::create(['account_id' => $this->customer->id, 'state' => 'Maharashtra', 'gst_registration_type' => 'unregistered']);
        $this->bank = Account::create(['name' => 'SBI', 'account_group_id' => AccountGroup::idFor('bank_accounts'), 'account_type' => 'bank']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    protected function save(array $rows)
    {
        return app(OpeningBills::class)->save($this->customer->fresh(), $rows);
    }

    protected function refused(array $rows, string $key, string $needle): void
    {
        try {
            $this->save($rows);
            $this->fail('Accepted');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($key, $e->errors());
            $this->assertStringContainsString($needle, $e->errors()[$key][0]);
        }
    }

    protected function receipt(string $amount, array $bills, string $date = '2026-05-10')
    {
        return app(VoucherService::class)->create(['voucher_type_id' => VoucherType::where('base_type', 'receipt')->value('id'), 'date' => $date, 'narration' => 'Received', 'entries' => [
            ['account_id' => $this->bank->id, 'side' => 'Dr', 'amount' => $amount],
            ['account_id' => $this->customer->id, 'side' => 'Cr', 'amount' => $amount, 'bills' => $bills],
        ]], $this->owner);
    }

    public function test_opening_bills_age_and_are_settled_agst_ref(): void
    {
        $this->save([
            ['bill_ref' => 'OLD/101', 'date' => '2026-01-10', 'due_date' => '2026-02-09', 'amount' => '30,000', 'side' => 'Dr'],
            ['bill_ref' => 'OLD/115', 'date' => '2026-03-20', 'due_date' => '2026-06-30', 'amount' => '15000', 'side' => 'Dr'],
            ['bill_ref' => '', 'date' => '', 'amount' => ''], // blank row
        ]);
        $this->assertSame(2, $this->customer->openingBills()->count());

        $outstanding = app(Outstanding::class);
        $bills = $outstanding->bills($this->customer, '2026-06-15');
        $this->assertSame(['OLD/101', 'OLD/115'], $bills->pluck('bill_ref')->all());
        $this->assertSame(['over_90', 'not_due'], $bills->pluck('bucket')->all());
        $this->assertTrue($bills[0]->opening);
        $this->assertNull($bills[0]->voucher_id);
        $row = $outstanding->summary('receivable', '2026-06-15')->firstWhere('account.id', $this->customer->id);
        $this->assertSame([5000000, 3000000, 1500000, 500000], [$row->balance, $row->over_90, $row->not_due, $row->on_account]);

        // Settled like any other bill, and over-settlement is refused.
        $this->receipt('20000', [['bill_type' => 'agst_ref', 'bill_ref' => 'old/101', 'amount' => '20000']]);
        $this->assertSame(1000000, $outstanding->bills($this->customer, '2026-06-15')->first()->amount);
        try {
            $this->receipt('11000', [['bill_type' => 'agst_ref', 'bill_ref' => 'OLD/101', 'amount' => '11000']]);
            $this->fail('Accepted');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('pending', collect($e->errors())->flatten()->implode(' '));
        }
        // A voucher cannot open a bill with an opening bill's reference.
        try {
            $this->receipt('100', [['bill_type' => 'advance', 'bill_ref' => 'OLD/115', 'amount' => '100']]);
            $this->fail('Accepted');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('already exists', collect($e->errors())->flatten()->implode(' '));
        }

        // A settled bill keeps its reference and at least the settled amount; unsettled ones can change or go.
        $ids = $this->customer->openingBills()->orderBy('date')->pluck('id')->all();
        $this->refused([['id' => $ids[0], 'bill_ref' => 'OLD/101', 'date' => '2026-01-10', 'amount' => '15000', 'side' => 'Dr']], 'bills.0.amount', 'already settled');
        $this->refused([['id' => $ids[0], 'bill_ref' => 'OLD/101-A', 'date' => '2026-01-10', 'amount' => '30000', 'side' => 'Dr']], 'bills.0.bill_ref', 'cannot change');
        $this->refused([['id' => $ids[1], 'bill_ref' => 'OLD/115', 'date' => '2026-03-20', 'amount' => '15000', 'side' => 'Dr']], 'bills', 'cannot be removed');
        $saved = $this->save([['id' => $ids[0], 'bill_ref' => 'OLD/101', 'date' => '2026-01-10', 'due_date' => '2026-02-09', 'amount' => '25000', 'side' => 'Dr']]);
        $this->assertSame(['OLD/101'], $saved->pluck('bill_ref')->all());
        $this->assertSame(500000, $outstanding->bills($this->customer, '2026-06-15')->first()->amount);
    }

    public function test_what_is_refused(): void
    {
        // More than the opening balance, or the wrong direction.
        $this->refused([['bill_ref' => 'A', 'date' => '2026-03-01', 'amount' => '50000.01', 'side' => 'Dr']], 'bills', 'opening balance is 50,000.00 Dr');
        $this->refused([['bill_ref' => 'A', 'date' => '2026-03-01', 'amount' => '100', 'side' => 'Cr']], 'bills', '100.00 Cr');
        // A bill after the books begin, a due date before the bill, duplicates and bad amounts.
        $this->refused([['bill_ref' => 'A', 'date' => '2026-04-02', 'amount' => '100', 'side' => 'Dr']], 'bills.0.date', 'on or before the books begin');
        $this->refused([['bill_ref' => 'A', 'date' => '2026-03-01', 'due_date' => '2026-02-01', 'amount' => '100', 'side' => 'Dr']], 'bills.0.due_date', 'before the bill date');
        $this->refused([['bill_ref' => 'A', 'date' => '2026-03-01', 'amount' => '100'], ['bill_ref' => 'a', 'date' => '2026-03-01', 'amount' => '100']], 'bills.1.bill_ref', 'twice');
        $this->refused([['bill_ref' => 'A', 'date' => '2026-03-01', 'amount' => '0']], 'bills.0.amount', 'above zero');
        // A bill and an advance can net within the balance.
        $this->assertCount(2, $this->save([['bill_ref' => 'A', 'date' => '2026-03-01', 'amount' => '60000', 'side' => 'Dr'], ['bill_ref' => 'ADV', 'date' => '2026-03-05', 'amount' => '10000', 'side' => 'Cr']]));

        // The ledger then can't drop below its bills or stop being bill-wise.
        $this->actingAs($this->owner);
        $form = ['name' => 'Mehta Stores', 'account_group_id' => AccountGroup::idFor('sundry_debtors'), 'opening_balance' => '40000', 'opening_balance_type' => 'Dr', 'maintain_bill_wise' => '1', 'is_active' => '1'];
        $this->put(route('ledgers.update', $this->customer), $form)->assertSessionHasErrors(['opening_balance']);
        $this->put(route('ledgers.update', $this->customer), ['maintain_bill_wise' => '0', 'opening_balance' => '50000'] + $form)->assertSessionHasErrors(['maintain_bill_wise']);
        $this->put(route('ledgers.update', $this->customer), ['opening_balance' => '55000'] + $form)->assertSessionHasNoErrors();

        // Not for ledgers kept on account.
        $this->customer->update(['maintain_bill_wise' => false]);
        $this->refused([['bill_ref' => 'B', 'date' => '2026-03-01', 'amount' => '1']], 'bills', 'bill-by-bill');
    }

    public function test_screen_and_permissions(): void
    {
        $this->actingAs($this->owner)->get(route('ledgers.edit', $this->customer))->assertSee('Opening bills');
        $this->get(route('customers.show', $this->customer))->assertSee(route('ledgers.opening-bills', $this->customer));
        $this->get(route('ledgers.opening-bills', $this->customer))->assertOk()->assertSee('Opening bills: Mehta Stores')->assertSee('Save opening bills');
        $this->put(route('ledgers.opening-bills.update', $this->customer), ['bills' => [
            ['bill_ref' => 'OLD/1', 'date' => '2026-03-01', 'due_date' => '2026-03-31', 'amount' => '20000', 'side' => 'Dr'],
        ]])->assertRedirect(route('ledgers.opening-bills', $this->customer))->assertSessionHas('success', '1 opening bill saved. 30,000.00 of the opening balance is on account.');
        $this->put(route('ledgers.opening-bills.update', $this->customer), ['bills' => [['bill_ref' => 'X', 'date' => '2026-03-01', 'amount' => '90000', 'side' => 'Dr']]])
            ->assertSessionHasErrors('bills');
        $this->get(route('reports.outstanding.party', $this->customer))->assertOk()->assertSee('OLD/1')->assertSee('Opening');
        $this->get(route('customers.show', $this->customer))->assertOk()->assertSee('OLD/1');
        $this->assertStringContainsString('OLD/1', $this->get(route('ledgers.opening-bills', [$this->customer, 'export' => 'csv']))->streamedContent());
        $this->getJson(route('ledgers.open-bills', $this->customer))->assertJsonFragment(['ref' => 'OLD/1', 'pending' => '20000.00']);

        $viewer = $this->addMember($this->company, 'viewer');
        $this->actingAs($viewer)->get(route('ledgers.opening-bills', $this->customer))->assertOk()->assertDontSee('Save opening bills');
        $this->actingAs($viewer)->put(route('ledgers.opening-bills.update', $this->customer), ['bills' => []])->assertForbidden();
        $this->assertSame(1, $this->customer->openingBills()->count());
        $this->actingAs($this->owner)->get(route('ledgers.opening-bills', $this->bank))->assertNotFound();
    }
}
