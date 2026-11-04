<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Company;
use App\Models\User;
use App\Support\CompanyBackup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class BackupTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
        $this->company = $this->owner->companies()->create([
            'name' => 'Acme Traders', 'gstin' => '27AAAAA0000A1Z5', 'financial_year' => '2026-27', 'currency' => 'INR',
        ]);
        $c = $this->company;
        $as = fn () => $this->actingAs($this->owner);

        // A sub-group, ledgers and a cost centre.
        $as()->post(route('companies.account-groups.store', $c), [
            'name' => 'Local Customers', 'parent_id' => $c->accountGroups()->where('code', 'SUNDRY_DEBTORS')->value('id'),
        ]);
        $sales = $this->ledger('Sales', 'SALES_ACCOUNTS');
        $purchases = $this->ledger('Purchases', 'PURCHASE_ACCOUNTS');
        $rent = $this->ledger('Rent', 'INDIRECT_EXPENSES');
        $bank = $this->ledger('HDFC Bank', 'BANK_ACCOUNTS', ['opening_balance' => 50000]);
        $this->ledger('Capital', 'CAPITAL_ACCOUNT', ['opening_balance' => 60000, 'opening_balance_type' => 'cr']);
        $customer = $c->accounts()->create([
            'name' => 'Pune Stores', 'account_group_id' => $c->accountGroups()->where('name', 'Local Customers')->value('id'),
            'account_type' => 'assets', 'state_code' => '27',
        ]);
        $contractor = $this->ledger('Ravi Contractors', 'SUNDRY_CREDITORS', ['pan' => 'ABCDE1234F', 'tds_section' => '194C-O']);
        $as()->post(route('companies.cost-centres.store', $c), ['name' => 'Pune Branch']);

        $laptop = $c->stockItems()->create([
            'name' => 'Laptop', 'unit_id' => $c->units()->where('symbol', 'Nos')->value('id'),
            'opening_quantity' => 10, 'opening_rate' => 1000,
        ]);

        // GST sale and purchase with stock, a plain payment with cost centre, a TDS bill, payroll and a budget.
        foreach ([['sales', $sales, 4, 2500], ['purchase', $purchases, 2, 1200]] as [$type, $ledger, $qty, $rate]) {
            $as()->post(route('companies.gst-invoices.store', $c), [
                'type' => $type, 'date' => '2026-06-10', 'party_account_id' => $customer->id,
                'lines' => [['account_id' => $ledger->id, 'stock_item_id' => $laptop->id, 'quantity' => $qty, 'item_rate' => $rate, 'rate' => 18]],
            ])->assertSessionHasNoErrors();
        }
        $as()->post(route('companies.vouchers.store', $c), [
            'type' => 'payment', 'date' => '2026-06-15',
            'entries' => [
                ['account_id' => $rent->id, 'debit' => 5000, 'cost_centre_id' => $c->costCentres()->value('id')],
                ['account_id' => $bank->id, 'credit' => 5000],
            ],
        ])->assertSessionHasNoErrors();
        $as()->post(route('companies.tds.store', $c), [
            'date' => '2026-06-20', 'party_account_id' => $contractor->id, 'expense_account_id' => $rent->id, 'amount' => 10000,
        ])->assertSessionHasNoErrors();
        $employee = $c->employees()->create(['name' => 'Asha', 'basic' => 12000, 'hra' => 4000, 'other_allowances' => 2000]);
        $as()->post(route('companies.payroll.store', $c), ['month' => '2026-06', 'days' => [$employee->id => 30]])->assertSessionHasNoErrors();
        $as()->put(route('companies.budgets.update', $c), ['budgets' => [$rent->id => 100000]]);
    }

    protected function ledger(string $name, string $code, array $extra = []): Account
    {
        $group = $this->company->accountGroups()->where('code', $code)->firstOrFail();

        return $this->company->accounts()->create($extra + ['name' => $name, 'account_group_id' => $group->id, 'account_type' => $group->nature]);
    }

    /**
     * Key figures from the reports, to compare a company with its restored copy.
     */
    protected function fingerprint(Company $company, User $user): array
    {
        $get = fn (string $route, array $q = []) => $this->actingAs($user)->get(route($route, [$company, ...$q]))->assertOk();

        $tb = $get('companies.reports.trial-balance');
        $pl = $get('companies.reports.profit-and-loss');
        $bs = $get('companies.reports.balance-sheet');
        $stock = $get('companies.inventory.summary')->viewData('rows')->first();
        $gst = $get('companies.gst.gstr3b', ['month' => '2026-06']);
        $tds = $get('companies.tds.index')->viewData('sections');
        $cc = $get('companies.cost-centres.index')->viewData('rows')->first();
        $budget = $get('companies.budgets.index')->viewData('rows')->firstWhere('account.name', 'Rent');

        return [
            'tb' => [$tb->viewData('totalDebit'), $tb->viewData('totalCredit')],
            'pl' => $pl->viewData('netProfit'),
            'bs' => [$bs->viewData('totalAssets'), $bs->viewData('difference')],
            'stock' => [$stock->closing_qty, $stock->closing_value],
            'gst' => (array) $gst->viewData('payable'),
            'tds' => $tds->sum('tds'),
            'cost_centre' => [$cc->centre->name, $cc->expenses],
            'budget' => [$budget->budget, $budget->actual],
            'vouchers' => $company->vouchers()->orderBy('type')->orderBy('number')->get()->map(fn ($v) => $v->type.'#'.$v->number)->all(),
            'payslips' => $company->payrollRuns()->withCount('payslips')->get()->sum('payslips_count'),
            'subgroup_parent' => $company->accountGroups()->where('name', 'Local Customers')->first()?->parent?->code,
        ];
    }

    public function test_download_then_restore_gives_identical_books(): void
    {
        $response = $this->actingAs($this->owner)->get(route('companies.backup', $this->company))->assertOk();
        $json = $response->streamedContent();
        $this->assertStringContainsString('"format": "tallyerp-backup"', $json);

        $other = User::factory()->create();
        $this->actingAs($other)
            ->post(route('companies.restore.store'), [
                'backup' => UploadedFile::fake()->createWithContent('backup.json', $json),
                'name' => 'Acme Copy',
            ])
            ->assertRedirect();

        $copy = $other->companies()->where('name', 'Acme Copy')->firstOrFail();

        $this->assertSame(28 + 1, $copy->accountGroups()->count());
        $this->assertSame($this->fingerprint($this->company, $this->owner), $this->fingerprint($copy, $other));
    }

    public function test_restore_for_same_user_drops_duplicate_gstin(): void
    {
        $copy = CompanyBackup::restore(CompanyBackup::export($this->company), $this->owner);

        $this->assertSame('Acme Traders (restored)', $copy->name);
        $this->assertNull($copy->gstin);
        $this->assertSame(2026, (int) $copy->books_start_year);
    }

    public function test_restore_rejects_bad_files(): void
    {
        $upload = fn (string $content) => $this->actingAs($this->owner)->post(route('companies.restore.store'), [
            'backup' => UploadedFile::fake()->createWithContent('backup.json', $content),
        ]);

        $upload('not json')->assertSessionHasErrors('backup');
        $upload('{"hello": "world"}')->assertSessionHasErrors('backup');

        $backup = CompanyBackup::export($this->company);
        $backup['tables']['chart_of_accounts'][0]['account_group_id'] = 999999;
        $upload(json_encode($backup))->assertSessionHasErrors('backup');

        // Nothing half-restored.
        $this->assertSame(1, Company::count());
    }

    public function test_only_admins_can_download(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('companies.backup', $this->company))
            ->assertForbidden();

        $this->actingAs($this->owner)->get(route('companies.restore'))->assertOk()->assertSee('Restore Backup');
    }
}
