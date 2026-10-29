<?php

namespace Tests\Feature\Reports;

use App\Models\Account;
use App\Models\AccountGroup;
use App\Models\AuditLog;
use App\Models\Budget;
use App\Models\CostCategory;
use App\Models\CostCentre;
use App\Models\VoucherType;
use App\Services\Accounting\VoucherService;
use App\Services\Reports\BudgetVariance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Books: bank 80,000 opening; May: purchase 30,000, sales 50,000, rent 5,000
 * from bank, customer pays 20,000, and rent 3,000 for the Pune branch (a
 * sub-cost-centre of Branches). Budget year 2026-27 has 365 days; up to
 * 31 May is 61 days of it.
 */
class BudgetTest extends TestCase
{
    use RefreshDatabase;

    protected $owner;
    protected $company;
    protected array $l = [];
    protected CostCentre $branches;
    protected CostCentre $pune;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->owner, $this->company] = $this->createCompanyWithOwner();

        $make = fn ($name, $group, $type, $opening = 0) => Account::create([
            'name' => $name, 'account_group_id' => AccountGroup::idFor($group), 'account_type' => $type,
            'opening_balance' => $opening, 'opening_balance_type' => 'Dr',
        ]);
        $this->l['bank'] = $make('HDFC Bank', 'bank_accounts', 'bank', 80000);
        $this->l['customer'] = $make('Customer', 'sundry_debtors', 'party');
        $this->l['supplier'] = $make('Supplier', 'sundry_creditors', 'party');
        $this->l['rent'] = $make('Rent', 'indirect_expenses', 'general');
        $category = CostCategory::first();
        $this->branches = CostCentre::create(['name' => 'Branches', 'cost_category_id' => $category->id]);
        $this->pune = CostCentre::create(['name' => 'Pune', 'cost_category_id' => $category->id, 'parent_id' => $this->branches->id]);

        $this->book('purchase', '2026-05-01', [[Account::idFor('purchase'), 'Dr', '30000'], [$this->l['supplier']->id, 'Cr', '30000']]);
        $this->book('sales', '2026-05-05', [[$this->l['customer']->id, 'Dr', '50000'], [Account::idFor('sales'), 'Cr', '50000']]);
        $this->book('payment', '2026-05-10', [[$this->l['rent']->id, 'Dr', '5000'], [$this->l['bank']->id, 'Cr', '5000']]);
        $this->book('receipt', '2026-05-15', [[$this->l['bank']->id, 'Dr', '20000'], [$this->l['customer']->id, 'Cr', '20000']]);
        $this->book('payment', '2026-05-25', [[$this->l['rent']->id, 'Dr', '3000', $this->pune->id], [$this->l['bank']->id, 'Cr', '3000']]);
    }

    protected function book(string $base, string $date, array $lines): void
    {
        app(VoucherService::class)->create([
            'voucher_type_id' => VoucherType::where('base_type', $base)->value('id'),
            'date' => $date,
            'entries' => array_map(fn ($l) => ['account_id' => $l[0], 'side' => $l[1], 'amount' => $l[2], 'cost_centre_id' => $l[3] ?? null], $lines),
        ], $this->owner);
    }

    protected function budget(array $lines, string $name = 'Budget 2026-27'): Budget
    {
        return app(BudgetVariance::class)->save(null, ['name' => $name, 'from_date' => '2026-04-01', 'to_date' => '2027-03-31', 'lines' => $lines], $this->owner);
    }

    public function test_variance_on_transactions_closing_balances_and_cost_centres(): void
    {
        $budget = $this->budget([
            ['target' => 'l:'.$this->l['rent']->id, 'basis' => 'nett', 'amount' => '36500'],
            ['target' => 'g:'.AccountGroup::idFor('sales_accounts'), 'basis' => 'nett', 'amount' => '365000'],
            ['target' => 'g:'.AccountGroup::idFor('indirect_expenses'), 'cost_centre_id' => $this->branches->id, 'basis' => 'nett', 'amount' => '3650'],
            ['target' => 'l:'.$this->l['bank']->id, 'basis' => 'closing', 'amount' => '90000'],
            ['target' => 'g:'.AccountGroup::idFor('purchase_accounts'), 'basis' => 'nett', 'amount' => '365000'],
        ]);

        $report = app(BudgetVariance::class)->report($budget, '2026-05-31');
        $this->assertSame([61, 365], [$report['elapsed'], $report['days']]);
        $rows = $report['rows']->values();

        // Rent: 36,500 a year is 6,100 for 61 days; 8,000 spent, so 1,900 over.
        $this->assertSame([3650000, 610000, 800000, 190000, 'over'], [$rows[0]->budget, $rows[0]->budget_to_date, $rows[0]->actual, $rows[0]->variance, $rows[0]->flag]);
        $this->assertEquals(131.1, $rows[0]->percent);
        // Sales: 61,000 expected, 50,000 made, so 11,000 short.
        $this->assertSame([6100000, 5000000, -1100000, 'short'], [$rows[1]->budget_to_date, $rows[1]->actual, $rows[1]->variance, $rows[1]->flag]);
        // Branches includes the Pune sub-cost-centre: only the 3,000 tagged to it, against 610.
        $this->assertSame([61000, 300000, 'over'], [$rows[2]->budget_to_date, $rows[2]->actual, $rows[2]->flag]);
        // Bank closing balance: 80,000 + 20,000 - 5,000 - 3,000 against 90,000, not pro-rated.
        $this->assertSame([9000000, 9200000, 200000, null], [$rows[3]->budget_to_date, $rows[3]->actual, $rows[3]->variance, $rows[3]->flag]);
        // Purchases under budget: no flag.
        $this->assertSame([6100000, 3000000, null], [$rows[4]->budget_to_date, $rows[4]->actual, $rows[4]->flag]);

        // Report dates are kept inside the budget, and the whole year gives the whole budget.
        $this->assertSame('2027-03-31', app(BudgetVariance::class)->report($budget, '2028-01-01')['as_of']);
        $this->assertSame(3650000, app(BudgetVariance::class)->report($budget, '2027-03-31')['rows'][0]->budget_to_date);
        $this->assertSame(0, app(BudgetVariance::class)->report($budget, '2026-04-01')['rows'][0]->actual);
    }

    public function test_cancelled_vouchers_do_not_count(): void
    {
        $budget = $this->budget([['target' => 'l:'.$this->l['rent']->id, 'basis' => 'nett', 'amount' => '36500']]);
        $voucher = \App\Models\Voucher::query()->where('date', '2026-05-25')->first();
        app(VoucherService::class)->cancel($voucher, $this->owner, 'Wrong branch');

        $this->assertSame(500000, app(BudgetVariance::class)->report($budget, '2026-05-31')['rows'][0]->actual);
    }

    public function test_validation(): void
    {
        $rent = 'l:'.$this->l['rent']->id;
        $fails = function (array $data, string $key) {
            try {
                app(BudgetVariance::class)->save(null, $data + ['name' => 'X', 'from_date' => '2026-04-01', 'to_date' => '2027-03-31'], $this->owner);
                $this->fail("Expected an error on {$key}");
            } catch (ValidationException $e) {
                $this->assertArrayHasKey($key, $e->errors());
            }
        };

        $fails(['lines' => [['target' => $rent, 'amount' => '100'], ['target' => $rent, 'amount' => '200']]], 'lines.1.target');
        $fails(['lines' => [['target' => $rent, 'cost_centre_id' => $this->pune->id, 'basis' => 'closing', 'amount' => '100']]], 'lines.0.basis');
        $fails(['lines' => [['target' => '', 'amount' => '']]], 'lines');
        $fails(['lines' => [['target' => 'l:999999', 'amount' => '5']]], 'lines.0.target');
        $fails(['lines' => [['target' => $rent, 'amount' => '12abc']]], 'lines.0.amount');
        $fails(['to_date' => '2026-03-01', 'lines' => [['target' => $rent, 'amount' => '1']]], 'to_date');
        $fails(['to_date' => '2027-06-30', 'lines' => [['target' => $rent, 'amount' => '1']]], 'to_date');

        // The same ledger for two different cost centres is fine; blank rows are skipped.
        $ok = $this->budget([
            ['target' => $rent, 'amount' => '100'],
            ['target' => $rent, 'cost_centre_id' => $this->pune->id, 'amount' => '50'],
            ['target' => '', 'amount' => ''],
        ]);
        $this->assertCount(2, $ok->lines);
    }

    public function test_screens_copy_and_delete(): void
    {
        $this->actingAs($this->owner);
        $rent = 'l:'.$this->l['rent']->id;

        $this->get(route('budgets.create'))->assertOk()->assertSee('New budget');
        $this->post(route('budgets.store'), [
            'name' => 'Plan 2026-27', 'from_date' => '2026-04-01', 'to_date' => '2027-03-31', 'description' => 'Board approved',
            'lines' => [['target' => $rent, 'cost_centre_id' => '', 'basis' => 'nett', 'amount' => '36,500']],
        ])->assertRedirect();
        $budget = Budget::firstWhere('name', 'Plan 2026-27');
        $this->assertSame('36500.00', (string) $budget->lines->first()->amount);
        $this->assertTrue(AuditLog::query()->where('auditable_type', Budget::class)->where('auditable_id', $budget->id)->exists());

        $this->get(route('budgets.index'))->assertOk()->assertSee('Plan 2026-27');
        $this->get(route('budgets.show', [$budget, 'as_of' => '2026-05-31']))->assertOk()->assertSee('Over budget')->assertSee('131.1%');
        $csv = $this->get(route('budgets.show', [$budget, 'as_of' => '2026-05-31', 'export' => 'csv']));
        $this->assertStringContainsString('Rent,,"Transactions in the period",36500.00,6100.00,8000.00,1900.00,131.1%,"Over budget"', $csv->streamedContent());
        $this->get(route('budgets.edit', $budget))->assertOk()->assertSee('36500.00');

        $this->put(route('budgets.update', $budget), [
            'name' => 'Plan 2026-27', 'from_date' => '2026-04-01', 'to_date' => '2027-03-31',
            'lines' => [['target' => $rent, 'basis' => 'nett', 'amount' => '40000'], ['target' => 'g:'.AccountGroup::idFor('sales_accounts'), 'basis' => 'nett', 'amount' => '500000']],
        ])->assertRedirect(route('budgets.show', $budget));
        $this->assertCount(2, $budget->fresh()->lines);

        $this->post(route('budgets.store'), ['name' => 'Plan 2026-27', 'from_date' => '2026-04-01', 'to_date' => '2027-03-31', 'lines' => [['target' => $rent, 'amount' => '1']]])
            ->assertSessionHasErrors('name');

        $this->post(route('budgets.copy', $budget))->assertRedirect();
        $copy = Budget::where('id', '!=', $budget->id)->first();
        $this->assertSame(['Plan 2026-27 (2027)', '2027-04-01', '2028-03-31', 2], [$copy->name, $copy->from_date->toDateString(), $copy->to_date->toDateString(), $copy->lines()->count()]);

        // A ledger in a budget cannot be deleted; deleting the budget frees it.
        $this->assertTrue(Account::find($this->l['rent']->id)->hasTransactions());
        $this->delete(route('budgets.destroy', $copy))->assertRedirect(route('budgets.index'));
        $this->assertDatabaseMissing('budget_lines', ['budget_id' => $copy->id]);
    }

    public function test_permissions_and_company_isolation(): void
    {
        $budget = $this->budget([['target' => 'l:'.$this->l['rent']->id, 'amount' => '100']]);

        $viewer = $this->addMember($this->company, 'viewer');
        $this->actingAs($viewer);
        $this->get(route('budgets.index'))->assertOk();
        $this->get(route('budgets.show', $budget))->assertOk()->assertDontSee('Copy to next year');
        $this->get(route('budgets.create'))->assertForbidden();
        $this->post(route('budgets.copy', $budget))->assertForbidden();
        $this->delete(route('budgets.destroy', $budget))->assertForbidden();
        $this->get(route('budgets.show', [$budget, 'export' => 'csv']))->assertForbidden();

        [, $other] = $this->createCompanyWithOwner(['name' => 'Other Co'], $this->owner);
        $this->actingAs($this->owner);
        $this->get(route('budgets.show', $budget))->assertNotFound();
        // A ledger of another company cannot be budgeted.
        $this->post(route('budgets.store'), ['name' => 'Sneaky', 'from_date' => '2026-04-01', 'to_date' => '2027-03-31', 'lines' => [['target' => 'l:'.$this->l['rent']->id, 'amount' => '1']]])
            ->assertSessionHasErrors('lines.0.target');
    }
    public function test_monthly_phasing(): void
    {
        $service = app(BudgetVariance::class);
        $rentLine = ['target' => 'l:'.$this->l['rent']->id, 'basis' => 'nett', 'amount' => '36500'];
        $budget = $this->budget([$rentLine, ['target' => 'l:'.$this->l['bank']->id, 'basis' => 'closing', 'amount' => '90000']]);
        $line = $budget->lines[0];
        $this->assertCount(12, $budget->periods());
        $this->assertSame(['2026-04-01', '2026-04-30', 'Apr 2026'], $budget->periods()[0]);
        // Even by days: April 3,000, May 3,100, adding up to the year.
        $even = $line->monthly($budget);
        $this->assertSame([300000, 310000], array_slice($even, 0, 2));
        $this->assertSame(3650000, array_sum($even));

        // Nothing in April, 10,000 in May, 2,650 a month after.
        $months = array_merge(['0', '10000'], array_fill(0, 10, '2,650'));
        $budget = $service->savePhasing($budget, [$line->id => $months, $budget->lines[1]->id => $months]);
        $line = $budget->lines[0];
        $this->assertTrue($line->isPhased());
        $this->assertFalse($budget->lines[1]->isPhased()); // closing balances are not phased
        $this->assertSame('36500.00', $line->amount);

        $row = $service->report($budget, '2026-05-31')['rows'][0];
        $this->assertSame([1000000, 800000, -200000, null], [$row->budget_to_date, $row->actual, $row->variance, $row->flag]);
        $this->assertSame(intdiv(1000000 * 16, 31), $service->report($budget, '2026-05-16')['rows'][0]->budget_to_date);
        $this->assertSame(0, $service->report($budget, '2026-04-20')['rows'][0]->budget_to_date);

        // Months that add up to more change the annual budget.
        $months[11] = '3650';
        $this->assertSame('37500.00', $service->savePhasing($budget, [$line->id => $months])->lines[0]->amount);
        $this->assertArrayHasKey("months.{$line->id}", $this->validationErrors(fn () => $service->savePhasing($budget, [$line->id => ['1', '2']])));

        // Altering the budget keeps phasing on unchanged lines and drops it where the amount changes.
        $budget = $service->save($budget->fresh(), ['name' => $budget->name, 'from_date' => '2026-04-01', 'to_date' => '2027-03-31', 'lines' => [['amount' => '37500'] + $rentLine]], $this->owner);
        $this->assertTrue($budget->lines[0]->isPhased());
        $copy = $service->copyToNextYear($budget, $this->owner);
        $this->assertSame($budget->lines[0]->phasing, $copy->lines[0]->phasing);
        $budget = $service->save($budget->fresh(), ['name' => $budget->name, 'from_date' => '2026-04-01', 'to_date' => '2027-03-31', 'lines' => [['amount' => '40000'] + $rentLine]], $this->owner);
        $this->assertFalse($budget->lines[0]->isPhased());

        // Back to even.
        $budget = $service->savePhasing($copy, [$copy->lines[0]->id => []]);
        $this->assertFalse($budget->lines[0]->isPhased());
    }

    public function test_phasing_screen(): void
    {
        $budget = $this->budget([['target' => 'l:'.$this->l['rent']->id, 'basis' => 'nett', 'amount' => '36500']]);
        $line = $budget->lines[0];
        $this->actingAs($this->owner)->get(route('budgets.phasing', $budget))->assertOk()->assertSee('Monthly phasing')->assertSee('Mar 2027')->assertSee('3100.00');
        $months = array_merge(['0', '10000'], array_fill(0, 10, '2650'));
        $this->put(route('budgets.phasing.update', $budget), ['mode' => [$line->id => 'monthly'], 'months' => [$line->id => $months]])->assertRedirect(route('budgets.show', $budget));
        $this->get(route('budgets.show', $budget))->assertSee('Phased by month');

        $viewer = $this->addMember($this->company, 'viewer');
        $this->actingAs($viewer)->get(route('budgets.phasing', $budget))->assertForbidden();
    }

    protected function validationErrors(callable $fn): array
    {
        try {
            $fn();
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $e->errors();
        }
        $this->fail('Expected a validation error.');
    }
}
