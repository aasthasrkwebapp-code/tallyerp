<?php

namespace App\Services\Setup;

use App\Models\Company;
use Illuminate\Support\Facades\DB;

/**
 * Payroll ledgers, the standard pay heads (basic, HRA, PF, ESI,
 * professional tax, TDS, overtime, loan recovery) and leave types for a company.
 */
class DefaultPayroll
{
    /** [system_key, name, group key, account type] */
    public const LEDGERS = [
        ['salaries', 'Salaries & Wages', 'indirect_expenses', 'general'],
        ['employer_pf', 'Employer PF Contribution', 'indirect_expenses', 'general'],
        ['employer_esi', 'Employer ESI Contribution', 'indirect_expenses', 'general'],
        ['salary_payable', 'Salary Payable', 'current_liabilities', 'general'],
        ['pf_payable', 'PF Payable', 'current_liabilities', 'general'],
        ['esi_payable', 'ESI Payable', 'current_liabilities', 'general'],
        ['pt_payable', 'Professional Tax Payable', 'duties_taxes', 'general'],
        ['tds_salary', 'TDS on Salary Payable', 'duties_taxes', 'general'],
        ['salary_advances', 'Salary Advances & Loans to Employees', 'loans_advances_asset', 'general'],
    ];

    /** [system_key, name, type, calculation, rate, is_basic, prorate, ledger key, payable ledger key, sort order (default: position)] */
    public const PAY_HEADS = [
        ['basic', 'Basic Salary', 'earning', 'flat', null, true, true, 'salaries', null],
        ['hra', 'House Rent Allowance', 'earning', 'percent_of_basic', '40', false, true, 'salaries', null],
        ['special', 'Special Allowance', 'earning', 'flat', null, false, true, 'salaries', null],
        ['pf_employee', 'Employee PF', 'deduction', 'pf', '12', false, false, 'pf_payable', null],
        ['esi_employee', 'Employee ESI', 'deduction', 'esi', '0.75', false, false, 'esi_payable', null],
        ['pt', 'Professional Tax', 'deduction', 'pt', null, false, false, 'pt_payable', null],
        ['tds', 'TDS on Salary', 'deduction', 'flat', null, false, false, 'tds_salary', null],
        ['pf_employer', 'Employer PF', 'employer', 'pf', '12', false, false, 'employer_pf', 'pf_payable'],
        ['esi_employer', 'Employer ESI', 'employer', 'esi', '3.25', false, false, 'employer_esi', 'esi_payable'],
        ['overtime', 'Overtime', 'earning', 'overtime', null, false, false, 'salaries', null, 2],
        ['loan_recovery', 'Loan / Advance Recovery', 'deduction', 'loan', null, false, false, 'salary_advances', null, 6],
    ];

    /** [code, name, paid, days a year] */
    public const LEAVE_TYPES = [
        ['CL', 'Casual Leave', true, '12'],
        ['SL', 'Sick Leave', true, '12'],
        ['EL', 'Earned Leave', true, '15'],
        ['LWP', 'Leave Without Pay', false, null],
    ];

    public function seed(Company $company): void
    {
        static::seedRows($company->id);
    }

    /** Plain inserts, also used by the migration for existing companies. */
    public static function seedRows(int $companyId): void
    {
        $now = now();
        $groups = DB::table('account_groups')->where('company_id', $companyId)->whereNotNull('system_key')->pluck('id', 'system_key');
        $ledgers = [];

        foreach (self::LEDGERS as [$key, $name, $groupKey, $type]) {
            $id = DB::table('chart_of_accounts')->where('company_id', $companyId)->where(fn ($q) => $q->where('system_key', $key)->orWhere('name', $name))->value('id');
            if (! $id && isset($groups[$groupKey])) {
                $id = DB::table('chart_of_accounts')->insertGetId([
                    'company_id' => $companyId, 'account_group_id' => $groups[$groupKey], 'name' => $name, 'system_key' => $key,
                    'account_type' => $type, 'opening_balance' => 0, 'opening_balance_type' => in_array($key, ['salaries', 'salary_advances'], true) ? 'Dr' : 'Cr',
                    'is_system' => true, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
                ]);
            }
            $ledgers[$key] = $id;
        }

        foreach (self::PAY_HEADS as $i => $row) {
            [$key, $name, $type, $calculation, $rate, $isBasic, $prorate, $ledger, $payable] = $row;
            if (! $ledgers[$ledger] || DB::table('pay_heads')->where('company_id', $companyId)->where(fn ($q) => $q->where('system_key', $key)->orWhere('name', $name))->exists()) {
                continue;
            }
            DB::table('pay_heads')->insert([
                'company_id' => $companyId, 'system_key' => $key, 'name' => $name, 'type' => $type, 'calculation' => $calculation,
                'rate' => $rate, 'is_basic' => $isBasic, 'prorate' => $prorate, 'account_id' => $ledgers[$ledger],
                'payable_account_id' => $payable ? $ledgers[$payable] : null, 'sort_order' => $row[9] ?? $i, 'is_active' => true,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        if (\Illuminate\Support\Facades\Schema::hasTable('leave_types')) {
            foreach (self::LEAVE_TYPES as [$code, $name, $paid, $days]) {
                if (DB::table('leave_types')->where('company_id', $companyId)->where(fn ($q) => $q->where('code', $code)->orWhere('name', $name))->exists()) {
                    continue;
                }
                DB::table('leave_types')->insert([
                    'company_id' => $companyId, 'code' => $code, 'name' => $name, 'is_paid' => $paid, 'annual_days' => $days,
                    'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        }
    }
}
