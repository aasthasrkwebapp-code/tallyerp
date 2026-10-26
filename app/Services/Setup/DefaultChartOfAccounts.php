<?php

namespace App\Services\Setup;

use App\Models\Account;
use App\Models\AccountGroup;
use App\Models\Company;
use App\Models\CostCategory;

/**
 * Seeds the standard group structure (15 primary groups and 13 sub-groups)
 * plus the ledgers every company needs from day one.
 */
class DefaultChartOfAccounts
{
    /** [system_key, name, nature, affects gross profit, parent key] */
    public const GROUPS = [
        // Balance sheet — liabilities
        ['capital_account', 'Capital Account', 'liability', false, null],
        ['reserves_surplus', 'Reserves & Surplus', 'liability', false, 'capital_account'],
        ['loans_liability', 'Loans (Liability)', 'liability', false, null],
        ['bank_od', 'Bank OD A/c', 'liability', false, 'loans_liability'],
        ['secured_loans', 'Secured Loans', 'liability', false, 'loans_liability'],
        ['unsecured_loans', 'Unsecured Loans', 'liability', false, 'loans_liability'],
        ['current_liabilities', 'Current Liabilities', 'liability', false, null],
        ['duties_taxes', 'Duties & Taxes', 'liability', false, 'current_liabilities'],
        ['provisions', 'Provisions', 'liability', false, 'current_liabilities'],
        ['sundry_creditors', 'Sundry Creditors', 'liability', false, 'current_liabilities'],
        ['suspense', 'Suspense A/c', 'liability', false, null],
        ['branch_divisions', 'Branch / Divisions', 'liability', false, null],
        // Balance sheet — assets
        ['fixed_assets', 'Fixed Assets', 'asset', false, null],
        ['investments', 'Investments', 'asset', false, null],
        ['current_assets', 'Current Assets', 'asset', false, null],
        ['bank_accounts', 'Bank Accounts', 'asset', false, 'current_assets'],
        ['cash_in_hand', 'Cash-in-Hand', 'asset', false, 'current_assets'],
        ['deposits_asset', 'Deposits (Asset)', 'asset', false, 'current_assets'],
        ['loans_advances_asset', 'Loans & Advances (Asset)', 'asset', false, 'current_assets'],
        ['stock_in_hand', 'Stock-in-Hand', 'asset', false, 'current_assets'],
        ['sundry_debtors', 'Sundry Debtors', 'asset', false, 'current_assets'],
        ['misc_expenses_asset', 'Misc. Expenses (Asset)', 'asset', false, null],
        // Profit & loss
        ['sales_accounts', 'Sales Accounts', 'income', true, null],
        ['direct_incomes', 'Direct Incomes', 'income', true, null],
        ['indirect_incomes', 'Indirect Incomes', 'income', false, null],
        ['purchase_accounts', 'Purchase Accounts', 'expense', true, null],
        ['direct_expenses', 'Direct Expenses', 'expense', true, null],
        ['indirect_expenses', 'Indirect Expenses', 'expense', false, null],
    ];

    /** [system_key, name, group key, type, extra attributes] */
    public const LEDGERS = [
        ['cash', 'Cash', 'cash_in_hand', 'cash', []],
        ['sales', 'Sales', 'sales_accounts', 'sales', []],
        ['purchase', 'Purchase', 'purchase_accounts', 'purchase', []],
        ['output_cgst', 'Output CGST', 'duties_taxes', 'tax', ['tax_type' => 'cgst']],
        ['output_sgst', 'Output SGST', 'duties_taxes', 'tax', ['tax_type' => 'sgst']],
        ['output_igst', 'Output IGST', 'duties_taxes', 'tax', ['tax_type' => 'igst']],
        ['input_cgst', 'Input CGST', 'duties_taxes', 'tax', ['tax_type' => 'cgst']],
        ['input_sgst', 'Input SGST', 'duties_taxes', 'tax', ['tax_type' => 'sgst']],
        ['input_igst', 'Input IGST', 'duties_taxes', 'tax', ['tax_type' => 'igst']],
        ['round_off', 'Round Off', 'indirect_expenses', 'general', []],
        ['discount_allowed', 'Discount Allowed', 'indirect_expenses', 'general', []],
        ['discount_received', 'Discount Received', 'indirect_incomes', 'general', []],
        ['bank_charges', 'Bank Charges', 'indirect_expenses', 'general', []],
        ['interest_received', 'Interest Received', 'indirect_incomes', 'general', []],
        ['capital', 'Capital', 'capital_account', 'general', []],
    ];

    public function seed(Company $company): void
    {
        $ids = [];
        foreach (self::GROUPS as $i => [$key, $name, $nature, $grossProfit, $parent]) {
            $group = AccountGroup::create([
                'company_id' => $company->id,
                'parent_id' => $parent ? $ids[$parent] : null,
                'name' => $name,
                'system_key' => $key,
                'nature' => $nature,
                'affects_gross_profit' => $grossProfit,
                'is_system' => true,
                'sort_order' => $i,
            ]);
            $ids[$key] = $group->id;
        }

        foreach (self::LEDGERS as [$key, $name, $groupKey, $type, $extra]) {
            Account::create(array_merge([
                'company_id' => $company->id,
                'account_group_id' => $ids[$groupKey],
                'name' => $name,
                'system_key' => $key,
                'account_type' => $type,
                'opening_balance' => 0,
                'opening_balance_type' => 'Dr',
                'is_system' => true,
                'is_active' => true,
            ], $extra));
        }

        CostCategory::create(['company_id' => $company->id, 'name' => 'Primary Cost Category']);
    }
}
