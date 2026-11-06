<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Opening: cash 10,000 + bank 40,000 = 50,000. Customer owes 5,000, supplier is owed 25,000.
 * Year: customer pays 5,000; rent 2,000 in cash; machinery 30,000 from bank;
 * loan 50,000 into bank; 1,000 cash deposited (no effect on total cash).
 * Net cash +23,000 → closing 73,000.
 */
class CashFlowRatiosTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = User::factory()->create()->companies()->create([
            'name' => 'Flow Co', 'financial_year' => '2026-27', 'currency' => 'INR',
        ]);
        $l = fn (string $name, string $code, array $extra = []) => $this->ledger($name, $code, $extra);

        $cash = $l('Cash', 'CASH_IN_HAND', ['opening_balance' => 10000]);
        $bank = $l('HDFC', 'BANK_ACCOUNTS', ['opening_balance' => 40000]);
        $customer = $l('Customer', 'SUNDRY_DEBTORS', ['opening_balance' => 5000]);
        $l('Supplier', 'SUNDRY_CREDITORS', ['opening_balance' => 25000, 'opening_balance_type' => 'cr']);
        $l('Capital', 'CAPITAL_ACCOUNT', ['opening_balance' => 30000, 'opening_balance_type' => 'cr']);
        $rent = $l('Rent', 'INDIRECT_EXPENSES');
        $machinery = $l('Machinery', 'FIXED_ASSETS');
        $loan = $l('SBI Loan', 'SECURED_LOANS');

        foreach ([
            ['receipt', [[$bank, 5000, 0], [$customer, 0, 5000]]],
            ['payment', [[$rent, 2000, 0], [$cash, 0, 2000]]],
            ['payment', [[$machinery, 30000, 0], [$bank, 0, 30000]]],
            ['receipt', [[$bank, 50000, 0], [$loan, 0, 50000]]],
            ['contra', [[$bank, 1000, 0], [$cash, 0, 1000]]],
        ] as [$type, $lines]) {
            $this->actingAs($this->company->user)->post(route('companies.vouchers.store', $this->company), [
                'type' => $type, 'date' => '2026-06-01',
                'entries' => array_map(fn ($e) => ['account_id' => $e[0]->id, 'debit' => $e[1] ?: null, 'credit' => $e[2] ?: null], $lines),
            ])->assertSessionHasNoErrors();
        }
    }

    protected function ledger(string $name, string $code, array $extra = []): Account
    {
        $group = $this->company->accountGroups()->where('code', $code)->firstOrFail();

        return $this->company->accounts()->create($extra + ['name' => $name, 'account_group_id' => $group->id, 'account_type' => $group->nature]);
    }

    public function test_cash_flow_by_activity(): void
    {
        $r = $this->actingAs($this->company->user)->get(route('companies.reports.cash-flow', $this->company))->assertOk();

        $this->assertSame(50000.0, $r->viewData('opening'));
        $this->assertSame(73000.0, $r->viewData('closing'));
        $this->assertSame(23000.0, $r->viewData('net'));

        $s = $r->viewData('sections');
        $this->assertSame(3000.0, $s['operating']->net);      // +5,000 − 2,000
        $this->assertSame(-30000.0, $s['investing']->net);
        $this->assertSame(50000.0, $s['financing']->net);
        // Contra between cash and bank isn't a flow.
        $this->assertSame(5000.0, $s['operating']->inflow);
    }

    public function test_ratios(): void
    {
        $r = $this->actingAs($this->company->user)->get(route('companies.reports.ratios', $this->company))->assertOk()->assertSee('Current ratio');

        $ratios = $r->viewData('ratios');
        $figures = $r->viewData('figures');
        $this->assertSame(48000.0, $figures['Working capital'][1]);           // 73,000 − 25,000
        $this->assertEqualsWithDelta(2.92, $ratios['Current ratio'][1], 0.0001);
        $this->assertEqualsWithDelta(50000 / 28000, $ratios['Debt / equity'][1], 0.0001); // equity 30,000 − 2,000 loss
        $this->assertNull($ratios['Gross profit %'][1]);                       // no sales
    }
}
