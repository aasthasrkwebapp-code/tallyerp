<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Company;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Deletes a company and all of its records.
 *
 * Accounting tables reference each other with RESTRICT foreign keys (a ledger
 * with postings cannot be deleted), so the company's rows are removed child
 * first, in the order below, before the company row itself.
 */
class CompanyDeletionService
{
    /** Tables with a company_id column, children before parents. */
    public const TABLES = [
        'audit_logs' => null, // kept; company_id is nulled by its foreign key
        'cost_centres' => 'parent_id',
        'cost_categories' => null,
        'chart_of_accounts' => null,
        'account_groups' => 'parent_id',
        'accounting_periods' => null,
        'financial_years' => null,
        'roles' => null,
    ];

    public function delete(Company $company): void
    {
        DB::transaction(function () use ($company) {
            AuditLog::record('company_deleted', $company, $company->only(['name', 'gstin']), null, 'Company deleted: '.$company->name);

            foreach (static::tables() as $table => $selfReference) {
                if ($table === 'audit_logs' || ! Schema::hasTable($table)) {
                    continue;
                }
                if ($selfReference) {
                    DB::table($table)->where('company_id', $company->id)->update([$selfReference => null]);
                }
                DB::table($table)->where('company_id', $company->id)->delete();
            }

            DB::table('company_user')->where('company_id', $company->id)->delete();
            $company->delete();
        });
    }

    /** Modules register their tables in config/erp.php ('deletion_tables'), first in, first deleted. */
    public static function tables(): array
    {
        return array_merge(config('erp.deletion_tables', []), self::TABLES);
    }
}
