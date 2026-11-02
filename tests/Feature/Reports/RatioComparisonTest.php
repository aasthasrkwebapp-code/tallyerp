<?php

namespace Tests\Feature\Reports;

use App\Models\Account;
use App\Models\AccountGroup;
use App\Models\VoucherType;
use App\Services\Accounting\VoucherService;
use App\Services\Reports\PeriodComparison;
use App\Services\Reports\RatioAnalysis;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Same books as FinancialReportsTest:
 *
 *   Opening: Capital Cr 1,00,000 · Bank Dr 80,000 · Cash Dr 20,000
 *   1 May purchase 30,000 on credit · 5 May sales 50,000 on credit
 *   10 May rent 5,000 from bank · 15 May customer pays 20,000
 *   20 May interest 1,000 in cash
 *
 *   As on 31 May: current assets 1,46,000 (bank 95,000, cash 21,000, debtor 30,000)
 *   current liabilities 30,000 · net worth 1,16,000 · gross profit 20,000 · net profit 16,000
 */
class RatioComparisonTest extends TestCase
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

        $this->book('purchase', '2026-05-01', [[Account::idFor('purchase'), 'Dr', '30000'], [$this->l['supplier']->id, 'Cr', '30000']]);
        $this->book('sales', '2026-05-05', [[$this->l['customer']->id, 'Dr', '50000'], [Account::idFor('sales'), 'Cr', '50000']]);
        $this->book('payment', '2026-05-10', [[$this->l['rent']->id, 'Dr', '5000'], [$this->l['bank']->id, 'Cr', '5000']]);
        $this->book('receipt', '2026-05-15', [[$this->l['bank']->id, 'Dr', '20000'], [$this->l['customer']->id, 'Cr', '20000']]);
        $this->book('receipt', '2026-05-20', [[Account::idFor('cash'), 'Dr', '1000'], [Account::idFor('interest_received'), 'Cr', '1000']]);
    }

    protected function book(string $base, string $date, array $lines): void
    {
        app(VoucherService::class)->create([
            'voucher_type_id' => VoucherType::where('base_type', $base)->value('id'),
            'date' => $date,
            'entries' => array_map(fn ($l) => ['account_id' => $l[0], 'side' => $l[1], 'amount' => $l[2]], $lines),
        ], $this->owner);
    }

    public function test_ratios_are_worked_out_from_the_statements(): void
    {
        $r = app(RatioAnalysis::class)->compute('2026-04-01', '2026-05-31');

        $this->assertSame(61, $r['days']);
        $this->assertSame([
            'working_capital' => 11600000, 'current_assets' => 14600000, 'current_liabilities' => 3000000,
            'cash' => 2100000, 'bank' => 9500000, 'bank_od' => 0, 'debtors' => 3000000, 'creditors' => 3000000,
            'stock' => 0, 'loans' => 0, 'net_worth' => 11600000, 'sales' => 5000000, 'purchases' => 3000000,
            'gross_profit' => 2000000, 'net_profit' => 1600000,
        ], $r['groups']);

        $ratios = $r['ratios'];
        $this->assertEquals(4.87, $ratios['current_ratio']);
        $this->assertEquals(4.87, $ratios['quick_ratio']);
        $this->assertEquals(0.0, $ratios['debt_equity']);
        $this->assertEquals(40.0, $ratios['gross_profit_pct']);
        $this->assertEquals(32.0, $ratios['net_profit_pct']);
        $this->assertEquals(68.0, $ratios['operating_cost_pct']);
        $this->assertEquals(36.6, $ratios['receivable_days']);
        $this->assertEquals(61.0, $ratios['payable_days']);
        $this->assertNull($ratios['inventory_turnover'], 'No stock: inventory turnover cannot be worked out.');
        $this->assertEquals(0.43, $ratios['working_capital_turnover']);
        $this->assertEquals(13.79, $ratios['return_on_investment']);
        $this->assertEquals(13.79, $ratios['return_on_working_capital']);

        $this->assertSame('4.87 : 1', RatioAnalysis::format(4.87, 'ratio'));
        $this->assertSame('37 days', RatioAnalysis::format(36.6, 'days'));
        $this->assertSame('n/a', RatioAnalysis::format(null, 'times'));
    }

    public function test_loans_and_bank_overdraft_count_as_debt(): void
    {
        $od = Account::create(['name' => 'SBI OD', 'account_group_id' => AccountGroup::idFor('bank_od'), 'account_type' => 'bank']);
        $this->book('payment', '2026-05-25', [[$this->l['supplier']->id, 'Dr', '29000'], [$od->id, 'Cr', '29000']]);

        $r = app(RatioAnalysis::class)->compute('2026-04-01', '2026-05-31');

        $this->assertSame(2900000, $r['groups']['bank_od']);
        $this->assertSame(2900000, $r['groups']['loans']);
        $this->assertSame(100000, $r['groups']['creditors']);
        $this->assertEquals(0.25, $r['ratios']['debt_equity']); // 29,000 / 1,16,000
        $this->assertEquals(146.0, $r['ratios']['current_ratio']); // 1,46,000 / 1,000
    }

    public function test_month_columns_match_rows_across_periods(): void
    {
        $comparison = app(PeriodComparison::class);
        $columns = $comparison->columns('month', '2026-04-01', '2026-05-31');
        $this->assertSame(['Apr 2026', 'May 2026'], array_column($columns, 'label'));
        $this->assertSame(['2026-04-30', '2026-05-31'], array_column($columns, 'to'));

        $pl = $comparison->profitAndLoss($columns, detailed: true);
        $row = fn (string $label) => $pl['rows']->firstWhere('label', $label);
        $this->assertSame([0, 1600000], $row('Net profit / (loss)')->amounts);
        $this->assertSame([0, 2000000], $row('Gross profit / (loss)')->amounts);
        $this->assertSame([0, 500000], $row('Rent')->amounts, 'A ledger used only in May still has an April column.');
        $this->assertSame(1600000, $row('Net profit / (loss)')->total);

        $bs = $comparison->balanceSheet($columns, detailed: false);
        $total = fn (string $label) => $bs['rows']->firstWhere('label', $label)->amounts;
        $this->assertSame([10000000, 14600000], $total('Total assets'));
        $this->assertSame($total('Total assets'), $total('Total liabilities'));
        $this->assertSame([0, 1600000], $total('Profit & Loss A/c'));
    }

    public function test_stock_total_is_first_opening_and_last_closing(): void
    {
        \App\Models\StockItem::create(['name' => 'Widget', 'unit_id' => \App\Models\Unit::where('symbol', 'Nos')->value('id'),
            'opening_quantity' => '10', 'opening_value' => '1000']);
        $comparison = app(PeriodComparison::class);
        $pl = $comparison->profitAndLoss($comparison->columns('month', '2026-04-01', '2026-06-30'), detailed: false);

        $closing = $pl['rows']->firstWhere('label', 'Add: closing stock');
        $opening = $pl['rows']->firstWhere('label', 'Add: opening stock');
        $this->assertSame([100000, 100000, 100000], $closing->amounts);
        $this->assertSame(100000, $closing->total);
        $this->assertSame(100000, $opening->total);
        $this->assertSame(1600000, $pl['rows']->firstWhere('label', 'Net profit / (loss)')->total);
    }

    public function test_quarter_year_and_previous_year_columns(): void
    {
        $comparison = app(PeriodComparison::class);

        $quarters = $comparison->columns('quarter', '2026-04-01', '2027-03-31');
        $this->assertSame(['Q1 2026-27', 'Q2 2026-27', 'Q3 2026-27', 'Q4 2026-27'], array_column($quarters, 'label'));
        $this->assertSame(['2026-06-30', '2026-09-30', '2026-12-31', '2027-03-31'], array_column($quarters, 'to'));

        $partial = $comparison->columns('quarter', '2026-05-10', '2026-08-15');
        $this->assertSame([['2026-05-10', '2026-06-30'], ['2026-07-01', '2026-08-15']], array_map(fn ($c) => [$c['from'], $c['to']], $partial));

        $this->assertSame(['FY 2026-27'], array_column($comparison->columns('year', '2026-04-01', '2027-03-31'), 'label'));

        $previous = $comparison->columns('previous', '2026-04-01', '2026-05-31');
        $this->assertSame([['2025-04-01', '2025-05-31'], ['2026-04-01', '2026-05-31']], array_map(fn ($c) => [$c['from'], $c['to']], $previous));
        $this->assertSame([1600000, 100.0], [PeriodComparison::change([0, 1600000])[0], PeriodComparison::change([800000, 1600000])[1]]);
        $this->assertNull(PeriodComparison::change([0, 1600000])[1], 'No percentage on a zero base.');

        $this->assertCount(PeriodComparison::MAX_COLUMNS + 1, $comparison->columns('month', '2026-04-01', '2028-03-31'));
    }

    public function test_screens_render_and_export(): void
    {
        $this->actingAs($this->owner);

        $this->get(route('reports.ratios', ['from' => '2026-04-01', 'to' => '2026-05-31', 'compare' => 1]))
            ->assertOk()->assertSee('Current ratio')->assertSee('4.87 : 1')->assertSee('31 May 2025');
        $this->get(route('reports.ratios', ['from' => '2026-04-01', 'to' => '2026-05-31', 'export' => 'csv']))
            ->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');

        $this->get(route('reports.comparison', ['statement' => 'pl', 'mode' => 'month', 'from' => '2026-04-01', 'to' => '2026-06-30', 'detailed' => 1]))
            ->assertOk()->assertSee('Apr 2026')->assertSee('Jun 2026')->assertSee('Total')->assertSee('Rent');
        $this->get(route('reports.comparison', ['statement' => 'bs', 'mode' => 'previous', 'from' => '2026-04-01', 'to' => '2026-05-31']))
            ->assertOk()->assertSee('Change')->assertSee('Total assets');
        $this->get(route('reports.comparison', ['mode' => 'month', 'from' => '2026-04-01', 'to' => '2028-03-31']))
            ->assertOk()->assertSee('Only the first 12 columns');

        $csv = $this->get(route('reports.comparison', ['statement' => 'pl', 'mode' => 'previous', 'from' => '2026-04-01', 'to' => '2026-05-31', 'export' => 'csv']));
        $csv->assertOk();
        $body = $csv->streamedContent();
        $this->assertStringContainsString('Change %', $body);
        $this->assertStringContainsString('"Net profit / (loss)",0.00,16000.00,16000.00,', $body);

        $this->get(route('reports.comparison', ['mode' => 'weekly']))->assertSessionHasErrors('mode');
    }

    public function test_viewer_can_view_but_not_export(): void
    {
        $viewer = $this->addMember($this->company, 'viewer');
        $this->actingAs($viewer);

        $this->get(route('reports.ratios'))->assertOk();
        $this->get(route('reports.comparison'))->assertOk();
        $this->get(route('reports.ratios', ['export' => 'csv']))->assertForbidden();
        $this->get(route('reports.comparison', ['export' => 'xlsx']))->assertForbidden();

        $employee = $this->addMember($this->company, 'employee');
        $this->actingAs($employee)->get(route('reports.ratios'))->assertForbidden();
    }
}
