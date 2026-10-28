<?php

namespace Tests\Feature\Reports;

use App\Models\Account;
use App\Models\AccountGroup;
use App\Models\StockItem;
use App\Models\Unit;
use App\Models\VoucherType;
use App\Services\Accounting\VoucherService;
use App\Services\Reports\FundsFlow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Opening: capital 1,00,000 · bank 80,000 · cash 20,000.
 * May: purchase 30,000 and sales 50,000 on credit, rent 5,000 from bank,
 * customer pays 20,000, interest 1,000 in cash (net profit 16,000),
 * a term loan of 50,000 into the bank, and a computer for 15,000 from the bank.
 *
 * Sources: loan 50,000 + profit 16,000 = 66,000. Applications: fixed assets 15,000.
 * Working capital 1,00,000 → 1,51,000 (bank 1,30,000 + cash 21,000 + debtor 30,000 − creditor 30,000).
 */
class FundsFlowTest extends TestCase
{
    use RefreshDatabase;

    protected $owner;
    protected $company;
    protected array $l = [];

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
        $this->l['computer'] = $make('Computer', 'fixed_assets', 'general');
        $this->l['loan'] = $make('Term Loan', 'secured_loans', 'general');
        Account::where('system_key', 'capital')->update(['opening_balance' => 100000, 'opening_balance_type' => 'Cr']);
        Account::where('system_key', 'cash')->update(['opening_balance' => 20000, 'opening_balance_type' => 'Dr']);

        $this->book('purchase', '2026-05-01', [[Account::idFor('purchase'), 'Dr', '30000'], [$this->l['supplier']->id, 'Cr', '30000']]);
        $this->book('sales', '2026-05-05', [[$this->l['customer']->id, 'Dr', '50000'], [Account::idFor('sales'), 'Cr', '50000']]);
        $this->book('payment', '2026-05-10', [[$this->l['rent']->id, 'Dr', '5000'], [$this->l['bank']->id, 'Cr', '5000']]);
        $this->book('receipt', '2026-05-15', [[$this->l['bank']->id, 'Dr', '20000'], [$this->l['customer']->id, 'Cr', '20000']]);
        $this->book('receipt', '2026-05-20', [[Account::idFor('cash'), 'Dr', '1000'], [Account::idFor('interest_received'), 'Cr', '1000']]);
        $this->book('receipt', '2026-05-22', [[$this->l['bank']->id, 'Dr', '50000'], [$this->l['loan']->id, 'Cr', '50000']]);
        $this->book('payment', '2026-05-24', [[$this->l['computer']->id, 'Dr', '15000'], [$this->l['bank']->id, 'Cr', '15000']]);
    }

    protected function book(string $base, string $date, array $lines): void
    {
        app(VoucherService::class)->create([
            'voucher_type_id' => VoucherType::where('base_type', $base)->value('id'),
            'date' => $date,
            'entries' => array_map(fn ($l) => ['account_id' => $l[0], 'side' => $l[1], 'amount' => $l[2]], $lines),
        ], $this->owner);
    }

    public function test_sources_and_applications_explain_the_change_in_working_capital(): void
    {
        $f = app(FundsFlow::class)->statement('2026-04-01', '2026-05-31');

        $this->assertSame(['Loans (Liability)' => 5000000], $f['sources']->mapWithKeys(fn ($s) => [$s->group->name => $s->amount])->all());
        $this->assertSame(['Fixed Assets' => 1500000], $f['applications']->mapWithKeys(fn ($a) => [$a->group->name => $a->amount])->all());
        $this->assertSame(['Term Loan' => -5000000], $f['sources'][0]->ledgers->mapWithKeys(fn ($l) => [$l->account->name => $l->change])->all());
        $this->assertSame(1600000, $f['net_profit']);
        $this->assertSame([6600000, 1500000], [$f['total_sources'], $f['total_applications']]);

        $wc = $f['working_capital'];
        $this->assertSame([10000000, 15100000, 5100000], [$wc['opening'], $wc['closing'], $wc['change']]);
        $this->assertSame(0, $f['difference']);
        $this->assertEqualsCanonicalizing([
            'Bank Accounts' => [8000000, 13000000], 'Cash-in-Hand' => [2000000, 2100000],
            'Sundry Debtors' => [0, 3000000], 'Sundry Creditors' => [0, -3000000],
        ], $wc['rows']->mapWithKeys(fn ($r) => [$r->label => [$r->opening, $r->closing]])->all());
    }

    public function test_loss_repayment_and_stock(): void
    {
        StockItem::create(['name' => 'Widget', 'unit_id' => Unit::where('symbol', 'Nos')->value('id'), 'opening_quantity' => '10', 'opening_value' => '1000']);
        $this->book('payment', '2026-06-05', [[$this->l['loan']->id, 'Dr', '10000'], [$this->l['bank']->id, 'Cr', '10000']]);
        $this->book('payment', '2026-06-10', [[$this->l['rent']->id, 'Dr', '4000'], [$this->l['bank']->id, 'Cr', '4000']]);

        $f = app(FundsFlow::class)->statement('2026-06-01', '2026-06-30');

        $this->assertSame(-400000, $f['net_profit']);
        $this->assertTrue($f['sources']->isEmpty());
        $this->assertSame(['Loans (Liability)' => 1000000], $f['applications']->mapWithKeys(fn ($a) => [$a->group->name => $a->amount])->all());
        $this->assertSame([0, 1400000], [$f['total_sources'], $f['total_applications']]);
        $this->assertSame(-1400000, $f['working_capital']['change']);
        $this->assertSame(0, $f['difference']);
        $stock = $f['working_capital']['rows']->firstWhere('label', 'Closing stock');
        $this->assertSame([100000, 100000, 0], [$stock->opening, $stock->closing, $stock->change]);
    }

    public function test_screen_export_and_permissions(): void
    {
        $this->actingAs($this->owner);
        $this->get(route('reports.funds-flow', ['from' => '2026-04-01', 'to' => '2026-05-31', 'expand' => 1]))
            ->assertOk()->assertSee('Sources of funds')->assertSee('Loans (Liability)')->assertSee('Term Loan')
            ->assertSee('Increase in working capital')->assertSee('51,000.00')->assertDontSee('does not agree');
        $csv = $this->get(route('reports.funds-flow', ['from' => '2026-04-01', 'to' => '2026-05-31', 'export' => 'csv']))->streamedContent();
        $this->assertStringContainsString('"Loans (Liability)",Increase,50000.00', $csv);
        $this->assertStringContainsString('"Increase / (decrease) in working capital",,51000.00', $csv);

        $viewer = $this->addMember($this->company, 'viewer');
        $this->actingAs($viewer)->get(route('reports.funds-flow'))->assertOk();
        $this->get(route('reports.funds-flow', ['export' => 'pdf']))->assertForbidden();
    }
}
