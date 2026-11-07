<?php

namespace Tests\Feature;

use App\Models\BusinessDocument;
use App\Models\Company;
use App\Models\User;
use App\Support\CompanyBackup;
use Database\Seeders\BulkDataSeeder;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Whole-app integration check on two fully populated companies (the demo company
 * with payroll / TDS / forex, and Bulk Test Traders with every Vyapar-style module):
 * every screen opens, roles are enforced, the books balance, backup restores
 * everything and deleting a company leaves nothing behind.
 */
#[Group('integration')]
class IntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        (new DemoSeeder)->run();
        $bulk = new BulkDataSeeder;
        $bulk->entries = 300;
        $bulk->run();
    }

    public function test_every_module_is_wired_together(): void
    {
        $demo = Company::where('name', 'Demo Traders')->firstOrFail();
        $bulk = Company::where('name', 'Bulk Test Traders')->firstOrFail();
        $owner = $demo->user;

        foreach ([$demo, $bulk] as $company) {
            $this->assertEveryScreenOpens($company, $owner);
            $this->assertBooksBalance($company, $owner);
        }

        // The bulk company really does use every module.
        foreach ([
            'loans' => 2, 'priceLists' => 1, 'boms' => 1, 'productions' => 1, 'godowns' => 1, 'stockTransfers' => 1,
            'stockBatches' => 1, 'businessDocuments' => 4,
        ] as $relation => $min) {
            $this->assertGreaterThanOrEqual($min, $bulk->{$relation}()->count(), "Bulk company has no {$relation}");
        }
        $this->assertGreaterThan(0, DB::table('serial_numbers')->where('company_id', $bulk->id)->count());

        $this->assertViewerIsReadOnly($demo);
        $this->assertBackupRestoresEverything($bulk, $owner);
        $this->assertDeletingLeavesNothing([$demo, $bulk], $owner);
    }

    protected function assertEveryScreenOpens(Company $company, User $user): void
    {
        $failures = [];
        $opened = 0;

        foreach (Route::getRoutes() as $route) {
            $name = (string) $route->getName();
            if (! str_starts_with($name, 'companies.') || ! in_array('GET', $route->methods(), true) || ! in_array('company', $route->parameterNames(), true)) {
                continue;
            }
            if ($name === 'companies.backup') {
                continue; // a file download, checked below
            }

            foreach ($this->parameterSets($company, $name, $route->parameterNames()) as $params) {
                if ($params === null) {
                    continue; // nothing of that kind in this company
                }
                $status = $this->actingAs($user)->get(route($name, $params))->getStatusCode();
                $opened++;
                if ($status >= 400) {
                    $failures[] = "{$status} {$name} ".json_encode(array_map(fn ($p) => is_object($p) ? $p->getKey() : $p, $params));
                }
            }
        }

        $this->assertSame([], $failures, $company->name.": screens that don't open:\n".implode("\n", $failures));
        $this->assertGreaterThan(60, $opened, $company->name.": only {$opened} screens checked");
        $this->actingAs($user)->get(route('companies.backup', $company))->assertOk();
    }

    /**
     * Route parameters for a screen; several sets when one route serves several kinds.
     *
     * @return array<int, ?array>
     */
    protected function parameterSets(Company $company, string $name, array $names): array
    {
        $sets = [['company' => $company]];

        foreach (array_diff($names, ['company']) as $param) {
            $next = [];
            foreach ($sets as $set) {
                $values = match ($param) {
                    'type' => array_keys(BusinessDocument::TYPES),
                    'kind' => ['receivables', 'payables'],
                    'account_group' => [$company->accountGroups()->first()],
                    'account' => [$company->accounts()->first()],
                    'document' => [$company->businessDocuments()->where('type', $set['type'] ?? 'estimate')->first()],
                    'employee' => [$company->employees()->first()],
                    'loan' => [$company->loans()->first()],
                    'bom' => [$company->boms()->first()],
                    'price_list' => [$company->priceLists()->first()],
                    'stock_item' => [$company->stockItems()->first()],
                    'payroll' => [$company->payrollRuns()->first()],
                    'payslip' => [isset($set['payroll']) ? $set['payroll']->payslips()->first() : null],
                    // Invoice screens need a GST invoice; voucher screens a plain voucher.
                    'voucher' => [str_starts_with($name, 'companies.gst-invoices.')
                        ? $company->vouchers()->whereHas('gstLines')->where('type', 'sales')->first()
                        : $company->vouchers()->whereDoesntHave('gstLines')->first()],
                    default => [null],
                };
                foreach ($values as $value) {
                    $next[] = $value === null ? null : $set + [$param => $value];
                }
            }
            $sets = array_values(array_filter($next, fn ($s) => $s !== null)) ?: [null];
        }

        return $sets;
    }

    protected function assertBooksBalance(Company $company, User $user): void
    {
        $tb = $this->actingAs($user)->get(route('companies.reports.trial-balance', $company));
        $this->assertSame($tb->viewData('totalDebit'), $tb->viewData('totalCredit'), $company->name.': trial balance');

        $bs = $this->actingAs($user)->get(route('companies.reports.balance-sheet', $company));
        $this->assertEqualsWithDelta(0, $bs->viewData('difference'), 0.005, $company->name.': balance sheet');

        $cf = $this->actingAs($user)->get(route('companies.reports.cash-flow', $company));
        $this->assertEqualsWithDelta($cf->viewData('closing'), $cf->viewData('opening') + $cf->viewData('net'), 0.005, $company->name.': cash flow');
    }

    protected function assertViewerIsReadOnly(Company $company): void
    {
        $viewer = User::where('email', 'viewer@example.com')->firstOrFail();

        foreach (['reports.trial-balance', 'inventory.summary', 'vouchers.index', 'loans.index', 'cheques.index', 'fixed-assets.index', 'stock-items.index'] as $page) {
            $this->actingAs($viewer)->get(route('companies.'.$page, $company))->assertOk();
        }
        // Writing anywhere is refused, and admin screens are closed.
        $this->actingAs($viewer)->post(route('companies.vouchers.store', $company), [])->assertForbidden();
        $this->actingAs($viewer)->post(route('companies.godowns.store', $company), ['name' => 'X'])->assertForbidden();
        $this->actingAs($viewer)->post(route('companies.fixed-assets.post', $company), [])->assertForbidden();
        $this->actingAs($viewer)->get(route('companies.members.index', $company))->assertForbidden();
    }

    protected function assertBackupRestoresEverything(Company $company, User $user): void
    {
        $copy = CompanyBackup::restore(CompanyBackup::export($company), $user, 'Restored copy');

        foreach (['vouchers', 'stockItems', 'loans', 'godowns', 'stockTransfers', 'priceLists', 'boms', 'productions', 'businessDocuments', 'stockBatches', 'accounts'] as $relation) {
            $this->assertSame($company->{$relation}()->count(), $copy->{$relation}()->count(), "Backup lost {$relation}");
        }

        $totals = fn (Company $c) => [
            $this->actingAs($user)->get(route('companies.reports.trial-balance', $c))->viewData('totalDebit'),
            $this->actingAs($user)->get(route('companies.inventory.summary', $c))->viewData('rows')->sum('closing_value'),
        ];
        $this->assertSame($totals($company), $totals($copy), 'Restored company has different totals');
    }

    /**
     * @param  array<int, Company>  $companies
     */
    protected function assertDeletingLeavesNothing(array $companies, User $user): void
    {
        foreach (Company::whereIn('user_id', [$user->id])->get() as $company) {
            $this->actingAs($user)->delete(route('companies.destroy', $company))->assertRedirect();
        }

        $left = [];
        foreach (DB::select("select name from sqlite_master where type = 'table'") as $table) {
            $columns = array_column(DB::select('pragma table_info("'.$table->name.'")'), 'name');
            if (in_array('company_id', $columns, true) && ($n = DB::table($table->name)->count()) > 0) {
                $left[] = "{$table->name}: {$n}";
            }
        }
        $this->assertSame([], $left, 'Rows left after deleting every company');
        $this->assertSame([], DB::select('pragma foreign_key_check'), 'Broken foreign keys after deleting');
    }
}
