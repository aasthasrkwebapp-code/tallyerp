<?php

namespace Tests\Feature\Reports;

use App\Models\Account;
use App\Models\AccountGroup;
use App\Models\FinancialYear;
use App\Models\VoucherType;
use App\Services\Accounting\VoucherService;
use App\Services\Reports\AccountBalances;
use App\Services\Reports\FinancialStatements;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A small set of books whose statements are worked out by hand:
 *
 *   Opening: Capital Cr 1,00,000 · Bank Dr 80,000 · Cash Dr 20,000
 *   1 May   Purchase 30,000 on credit from Supplier
 *   5 May   Sales 50,000 on credit to Customer
 *   10 May  Rent 5,000 paid from bank
 *   15 May  Customer pays 20,000 into bank
 *   20 May  Interest received 1,000 in cash
 *
 *   Gross profit = 50,000 - 30,000 = 20,000
 *   Net profit   = 20,000 + 1,000 - 5,000 = 16,000
 *   Bank 95,000 · Cash 21,000 · Customer 30,000 = assets 1,46,000
 *   Capital 1,00,000 + Supplier 30,000 + profit 16,000 = 1,46,000
 */
class FinancialReportsTest extends TestCase
{
    use RefreshDatabase;

    protected $owner;
    protected $company;
    protected array $l = [];

    protected function setUp(): void
    {
        parent::setUp();
        [$this->owner, $this->company] = $this->createCompanyWithOwner();

        $make = fn ($name, $group, $type, $opening = 0, $side = 'Dr') => Account::create([
            'name' => $name, 'account_group_id' => AccountGroup::idFor($group), 'account_type' => $type,
            'opening_balance' => $opening, 'opening_balance_type' => $side,
        ]);

        $this->l['bank'] = $make('HDFC Bank', 'bank_accounts', 'bank', 80000);
        $this->l['customer'] = $make('Customer', 'sundry_debtors', 'party');
        $this->l['supplier'] = $make('Supplier', 'sundry_creditors', 'party');
        $this->l['rent'] = $make('Rent', 'indirect_expenses', 'general');
        Account::where('system_key', 'capital')->update(['opening_balance' => 100000, 'opening_balance_type' => 'Cr']);
        Account::where('system_key', 'cash')->update(['opening_balance' => 20000, 'opening_balance_type' => 'Dr']);

        $post = function (string $base, string $date, array $lines) {
            app(VoucherService::class)->create([
                'voucher_type_id' => VoucherType::where('base_type', $base)->value('id'),
                'date' => $date,
                'entries' => array_map(fn ($l) => ['account_id' => $l[0], 'side' => $l[1], 'amount' => $l[2]], $lines),
            ], $this->owner);
        };

        $post('purchase', '2026-05-01', [[Account::idFor('purchase'), 'Dr', '30000'], [$this->l['supplier']->id, 'Cr', '30000']]);
        $post('sales', '2026-05-05', [[$this->l['customer']->id, 'Dr', '50000'], [Account::idFor('sales'), 'Cr', '50000']]);
        $post('payment', '2026-05-10', [[$this->l['rent']->id, 'Dr', '5000'], [$this->l['bank']->id, 'Cr', '5000']]);
        $post('receipt', '2026-05-15', [[$this->l['bank']->id, 'Dr', '20000'], [$this->l['customer']->id, 'Cr', '20000']]);
        $post('receipt', '2026-05-20', [[Account::idFor('cash'), 'Dr', '1000'], [Account::idFor('interest_received'), 'Cr', '1000']]);
    }

    public function test_cash_flow_classifies_cash_movements(): void
    {
        $voucher = fn (string $base, string $date, array $lines) => app(VoucherService::class)->create([
            'voucher_type_id' => VoucherType::where('base_type', $base)->value('id'),
            'date' => $date,
            'entries' => array_map(fn ($l) => ['account_id' => $l[0], 'side' => $l[1], 'amount' => $l[2]], $lines),
        ], $this->owner);
        $computer = Account::create(['name' => 'Computer', 'account_group_id' => AccountGroup::idFor('fixed_assets'), 'account_type' => 'general']);
        $loan = Account::create(['name' => 'Term Loan', 'account_group_id' => AccountGroup::idFor('secured_loans'), 'account_type' => 'general']);

        $voucher('payment', '2026-05-21', [[$computer->id, 'Dr', '15000'], [$this->l['bank']->id, 'Cr', '15000']]);
        $voucher('receipt', '2026-05-22', [[$this->l['bank']->id, 'Dr', '40000'], [$loan->id, 'Cr', '40000']]);
        $voucher('contra', '2026-05-23', [[Account::idFor('cash'), 'Dr', '3000'], [$this->l['bank']->id, 'Cr', '3000']]);

        $cf = app(\App\Services\Reports\CashFlow::class)->statement('2026-04-01', '2026-05-31');

        $this->assertSame(10000000, $cf['opening']);               // bank 80k + cash 20k
        // Operating: rent −5,000, customer +20,000, interest +1,000.
        $this->assertSame(1600000, $cf['sections']['operating']['total']);
        $this->assertSame(-1500000, $cf['sections']['investing']['total']);
        $this->assertSame(4000000, $cf['sections']['financing']['total']);
        $this->assertSame(4100000, $cf['net']);                    // the contra transfer nets out
        $this->assertSame($cf['opening'] + $cf['net'], $cf['closing']);

        $this->actingAs($this->owner)->get(route('reports.cash-flow', ['from' => '2026-04-01', 'to' => '2026-05-31', 'expand' => 1]))
            ->assertOk()->assertSee('Fixed Assets')->assertSee('Term Loan')->assertSee('1,41,000.00');
        $this->get(route('reports.cash-flow', ['from' => '2026-04-01', 'to' => '2026-05-31', 'export' => 'csv']))->assertOk();
    }

    public function test_trial_balance_agrees(): void
    {
        $tb = app(FinancialStatements::class)->trialBalance('2026-04-01', '2026-05-31');

        $this->assertSame(10600000, $tb['totals']['debit']);   // 30k + 50k + 5k + 20k + 1k
        $this->assertSame($tb['totals']['debit'], $tb['totals']['credit']);
        $this->assertSame($tb['totals']['closing_dr'], $tb['totals']['closing_cr']);
        $this->assertSame(0, $tb['difference']);

        $bank = $tb['rows']->firstWhere('account.id', $this->l['bank']->id);
        $this->assertSame(8000000, $bank->opening);
        $this->assertSame(9500000, $bank->closing);
    }

    public function test_profit_and_loss(): void
    {
        $pl = app(FinancialStatements::class)->profitAndLoss('2026-04-01', '2026-05-31');

        $this->assertSame(2000000, $pl['gross_profit']);
        $this->assertSame(1600000, $pl['net_profit']);
    }

    public function test_balance_sheet_balances(): void
    {
        $bs = app(FinancialStatements::class)->balanceSheet('2026-05-31');

        $this->assertSame(14600000, $bs['totalAssets']);
        $this->assertSame(14600000, $bs['totalLiabilities']);
        $this->assertSame(1600000, $bs['currentProfit']);
    }

    public function test_cancelled_vouchers_are_excluded(): void
    {
        $sale = \App\Models\Voucher::where('voucher_type_id', VoucherType::where('base_type', 'sales')->value('id'))->first();
        // The receipt settles nothing bill-wise (no bill-wise ledger), so the sale can be cancelled.
        app(VoucherService::class)->cancel($sale, $this->owner, 'Wrong customer');

        $pl = app(FinancialStatements::class)->profitAndLoss('2026-04-01', '2026-05-31');
        $this->assertSame(-3400000, $pl['net_profit']); // 1,000 - 5,000 - 30,000

        $bs = app(FinancialStatements::class)->balanceSheet('2026-05-31');
        $this->assertSame($bs['totalAssets'], $bs['totalLiabilities']);
    }

    public function test_next_year_carries_profit_forward_and_resets_income_ledgers(): void
    {
        $this->company->update(['settings' => ['allow_future_dated_vouchers' => true]]);
        FinancialYear::open($this->company, '2027-04-01', makeCurrent: false);

        $tb = app(FinancialStatements::class)->trialBalance('2027-04-01', '2027-04-30');
        $sales = $tb['rows']->firstWhere('account.system_key', 'sales');
        $this->assertSame(0, $sales->opening);
        $this->assertSame(-1600000, $tb['accumulated']); // previous year's profit, a credit
        $this->assertSame($tb['totals']['closing_dr'], $tb['totals']['closing_cr']);

        $bs = app(FinancialStatements::class)->balanceSheet('2027-04-30');
        $this->assertSame(1600000, $bs['accumulatedProfit']);
        $this->assertSame(0, $bs['currentProfit']);
        $this->assertSame($bs['totalAssets'], $bs['totalLiabilities']);
    }

    public function test_opening_balance_difference_is_reported(): void
    {
        Account::where('system_key', 'cash')->update(['opening_balance' => 25000]);

        $tb = app(FinancialStatements::class)->trialBalance('2026-04-01', '2026-05-31');
        $this->assertSame(500000, $tb['difference']);
        $this->assertSame($tb['totals']['closing_dr'], $tb['totals']['closing_cr']);

        $bs = app(FinancialStatements::class)->balanceSheet('2026-05-31');
        $this->assertSame($bs['totalAssets'], $bs['totalLiabilities']);
    }

    public function test_report_screens_render_and_export(): void
    {
        $q = '?from=2026-04-01&to=2026-05-31';
        $this->actingAs($this->owner)->get('/reports/trial-balance'.$q)->assertOk()->assertSee('1,06,000.00');
        $this->actingAs($this->owner)->get('/reports/trial-balance'.$q.'&ledgers=1')->assertOk()->assertSee('HDFC Bank');
        $this->actingAs($this->owner)->get('/reports/profit-loss'.$q.'&expand=1')->assertOk()->assertSee('16,000.00');
        $this->actingAs($this->owner)->get('/reports/balance-sheet?to=2026-05-31&expand=1')->assertOk()->assertSee('1,46,000.00');
        $this->actingAs($this->owner)->get('/reports/day-book'.$q)->assertOk()->assertSee('INV/0001');
        $this->actingAs($this->owner)->get('/reports/ledger'.$q.'&ledger='.$this->l['bank']->id)->assertOk()->assertSee('95,000.00 Dr');
        $this->actingAs($this->owner)->get('/reports/ledger?book=cash')->assertOk();
        $this->actingAs($this->owner)->get('/reports/ledger?book=bank')->assertOk();
        $this->actingAs($this->owner)->get('/reports/groups/'.AccountGroup::idFor('current_assets').$q)->assertOk()->assertSee('Sundry Debtors');

        $csv = $this->actingAs($this->owner)->get('/reports/trial-balance'.$q.'&export=csv');
        $csv->assertOk();
        $this->assertStringContainsString('HDFC Bank', $csv->streamedContent());

        $ledgerCsv = $this->actingAs($this->owner)->get('/reports/ledger'.$q.'&export=csv&ledger='.$this->l['bank']->id);
        $this->assertStringContainsString('Closing balance', $ledgerCsv->streamedContent());
    }

    public function test_dashboard_shows_figures(): void
    {
        $this->actingAs($this->owner)->get('/dashboard')->assertOk()
            ->assertSee('Net profit')->assertSee('₹16,000.00')->assertSee('₹95,000.00');
    }

    public function test_viewer_can_view_but_not_export(): void
    {
        $viewer = $this->addMember($this->company, 'viewer');
        $this->actingAs($viewer)->get('/reports/trial-balance')->assertOk();
        $this->actingAs($viewer)->get('/reports/trial-balance?export=csv')->assertForbidden();
    }

    public function test_csv_cells_are_protected_against_formulas(): void
    {
        $this->assertSame("'=SUM(A1)", \App\Support\CsvExport::safe('=SUM(A1)'));
        $this->assertSame('-1200.00', \App\Support\CsvExport::safe('-1200.00'));
        $this->assertSame("'-cmd", \App\Support\CsvExport::safe('-cmd'));
    }
}
