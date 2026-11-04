<?php

namespace App\Support;

use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Exports a company's books to a JSON array and restores them as a new company.
 * Users, sharing and the audit log are not part of a backup.
 */
class CompanyBackup
{
    public const FORMAT = 'tallyerp-backup';

    public const VERSION = 1;

    /**
     * Tables in restore order. 'refs' maps a column to the table its id points to;
     * 'self' is a column pointing at the same table (restored in a second pass).
     * Rows are found through 'via' (a parent table) when they have no company_id.
     */
    protected const TABLES = [
        'currencies' => ['refs' => []],
        'account_groups' => ['refs' => [], 'self' => 'parent_id'],
        'chart_of_accounts' => ['refs' => ['account_group_id' => 'account_groups']],
        'units' => ['refs' => []],
        'stock_groups' => ['refs' => [], 'self' => 'parent_id'],
        'stock_items' => ['refs' => ['stock_group_id' => 'stock_groups', 'unit_id' => 'units']],
        'stock_batches' => ['refs' => ['stock_item_id' => 'stock_items']],
        'cost_centres' => ['refs' => [], 'self' => 'parent_id'],
        'vouchers' => ['refs' => ['party_account_id' => 'chart_of_accounts']],
        'voucher_entries' => ['refs' => ['voucher_id' => 'vouchers', 'account_id' => 'chart_of_accounts', 'cost_centre_id' => 'cost_centres'], 'via' => ['voucher_id', 'vouchers']],
        'voucher_gst_lines' => ['refs' => ['voucher_id' => 'vouchers', 'account_id' => 'chart_of_accounts', 'stock_item_id' => 'stock_items'], 'via' => ['voucher_id', 'vouchers']],
        'stock_movements' => ['refs' => ['voucher_id' => 'vouchers', 'stock_item_id' => 'stock_items', 'stock_batch_id' => 'stock_batches']],
        'tds_deductions' => ['refs' => ['voucher_id' => 'vouchers', 'party_account_id' => 'chart_of_accounts']],
        'employees' => ['refs' => []],
        'payroll_runs' => ['refs' => ['voucher_id' => 'vouchers']],
        'payslips' => ['refs' => ['payroll_run_id' => 'payroll_runs', 'employee_id' => 'employees'], 'via' => ['payroll_run_id', 'payroll_runs']],
        'budgets' => ['refs' => ['account_id' => 'chart_of_accounts']],
        'business_documents' => ['refs' => ['party_account_id' => 'chart_of_accounts', 'voucher_id' => 'vouchers']],
        'business_document_lines' => ['refs' => ['business_document_id' => 'business_documents', 'account_id' => 'chart_of_accounts', 'stock_item_id' => 'stock_items'], 'via' => ['business_document_id', 'business_documents']],
    ];

    protected const COMPANY_FIELDS = [
        'name', 'legal_name', 'gstin', 'pan', 'email', 'phone', 'address', 'city', 'pincode', 'state',
        'financial_year', 'books_start_year', 'currency',
    ];

    public static function export(Company $company): array
    {
        $tables = [];
        $ids = [];

        foreach (self::TABLES as $table => $spec) {
            $query = DB::table($table);

            if (isset($spec['via'])) {
                [$column, $parent] = $spec['via'];
                $query->whereIn($column, $ids[$parent] ?? []);
            } else {
                $query->where('company_id', $company->id);
            }

            $rows = $query->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
            $tables[$table] = $rows;
            $ids[$table] = array_column($rows, 'id');
        }

        return [
            'format' => self::FORMAT,
            'version' => self::VERSION,
            'exported_at' => now()->toIso8601String(),
            'company' => array_intersect_key($company->getAttributes(), array_flip(self::COMPANY_FIELDS)),
            'tables' => $tables,
        ];
    }

    /**
     * Create a new company for $owner from a backup array.
     *
     * @throws \InvalidArgumentException when the data is not a valid backup
     */
    public static function restore(array $backup, User $owner, ?string $name = null): Company
    {
        if (($backup['format'] ?? null) !== self::FORMAT || ! is_array($backup['company'] ?? null) || ! is_array($backup['tables'] ?? null)) {
            throw new \InvalidArgumentException('This file is not a TallyERP backup.');
        }
        if ((int) ($backup['version'] ?? 0) > self::VERSION) {
            throw new \InvalidArgumentException('This backup was made by a newer version of TallyERP.');
        }

        return DB::transaction(function () use ($backup, $owner, $name) {
            $fields = array_intersect_key($backup['company'], array_flip(self::COMPANY_FIELDS));
            $fields['name'] = $name ?: ($fields['name'] ?? 'Restored company').' (restored)';

            // GSTIN is unique per user: drop it if this user already has a company with it.
            if (! empty($fields['gstin']) && $owner->companies()->where('gstin', $fields['gstin'])->exists()) {
                $fields['gstin'] = null;
            }

            // Plain inserts: no default groups/units and no audit entries for restored rows.
            $now = now();
            $companyId = DB::table('companies')->insertGetId($fields + [
                'user_id' => $owner->id, 'created_at' => $now, 'updated_at' => $now,
            ]);

            $map = [];
            foreach (self::TABLES as $table => $spec) {
                $map[$table] = [];
                $selfLinks = [];

                foreach ($backup['tables'][$table] ?? [] as $row) {
                    if (! is_array($row) || ! isset($row['id'])) {
                        throw new \InvalidArgumentException("Damaged data in table {$table}.");
                    }

                    $oldId = $row['id'];
                    unset($row['id']);

                    if (array_key_exists('company_id', $row)) {
                        $row['company_id'] = $companyId;
                    }

                    foreach ($spec['refs'] as $column => $target) {
                        if (isset($row[$column])) {
                            $row[$column] = $map[$target][$row[$column]]
                                ?? throw new \InvalidArgumentException("Damaged data: {$table}.{$column} points to a missing record.");
                        }
                    }

                    if (isset($spec['self']) && isset($row[$spec['self']])) {
                        $selfLinks[$oldId] = $row[$spec['self']];
                        $row[$spec['self']] = null;
                    }

                    $map[$table][$oldId] = DB::table($table)->insertGetId(self::onlyColumns($table, $row));
                }

                foreach ($selfLinks as $oldId => $oldParent) {
                    DB::table($table)->where('id', $map[$table][$oldId])
                        ->update([$spec['self'] => $map[$table][$oldParent] ?? null]);
                }
            }

            return Company::findOrFail($companyId);
        });
    }

    /**
     * Ignore columns this installation doesn't have (e.g. a backup from a newer minor schema).
     */
    protected static function onlyColumns(string $table, array $row): array
    {
        static $columns = [];
        $columns[$table] ??= array_flip(DB::getSchemaBuilder()->getColumnListing($table));

        return array_intersect_key($row, $columns[$table]);
    }
}
