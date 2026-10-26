<?php

/*
|--------------------------------------------------------------------------
| ERP permission catalogue
|--------------------------------------------------------------------------
|
| Every permission key has the form "<module>.<action>". Routes are guarded
| with the `can:<key>` middleware and the Gate resolves the key against the
| role the user holds in the active company.
|
| Role grants accept wildcards: "*" (everything), "vouchers.*" (all actions
| of a module) and "*.view" (one action across every module).
|
*/

return [

    'require_email_verification' => (bool) env('ERP_REQUIRE_EMAIL_VERIFICATION', true),

    'modules' => [
        'company' => ['label' => 'Company & Settings', 'actions' => ['view', 'edit', 'delete']],
        'users' => ['label' => 'Users & Roles', 'actions' => ['view', 'create', 'edit', 'delete']],
        'periods' => ['label' => 'Financial Years & Period Locks', 'actions' => ['view', 'create', 'lock']],
        'masters' => ['label' => 'Accounting Masters (Groups, Ledgers, Cost Centres)', 'actions' => ['view', 'create', 'edit', 'delete']],
        'voucher_types' => ['label' => 'Voucher Types & Numbering', 'actions' => ['view', 'create', 'edit']],
        'vouchers' => ['label' => 'Vouchers', 'actions' => ['view', 'create', 'edit', 'delete', 'post', 'cancel', 'approve']],
        'sales' => ['label' => 'Sales & Receivables', 'actions' => ['view', 'create', 'edit', 'delete']],
        'purchases' => ['label' => 'Purchases & Payables', 'actions' => ['view', 'create', 'edit', 'delete']],
        'inventory' => ['label' => 'Inventory', 'actions' => ['view', 'create', 'edit', 'delete']],
        'banking' => ['label' => 'Banking', 'actions' => ['view', 'create', 'edit', 'delete']],
        'gst' => ['label' => 'GST & Tax', 'actions' => ['view', 'create', 'edit']],
        'payroll' => ['label' => 'Payroll', 'actions' => ['view', 'create', 'edit', 'delete']],
        'transport' => ['label' => 'Transport Accounting', 'actions' => ['view', 'create', 'edit', 'delete']],
        'reports' => ['label' => 'Reports', 'actions' => ['view', 'export']],
        'audit' => ['label' => 'Audit Trail', 'actions' => ['view']],
        'data' => ['label' => 'Data Management (Import, Export, Backup)', 'actions' => ['view', 'import', 'export', 'backup']],
    ],

    // Default grants for the system roles (seeded into the roles table).
    'role_grants' => [
        'owner' => ['*'],
        'admin' => ['*'],
        'accountant' => [
            'company.view', 'periods.*', 'masters.*', 'voucher_types.*', 'vouchers.*',
            'sales.*', 'purchases.*', 'banking.*', 'gst.*', 'reports.*', 'audit.view',
            'inventory.view', 'payroll.view', 'transport.view', 'data.view', 'data.export',
        ],
        'sales' => [
            'company.view', 'masters.view', 'vouchers.view', 'vouchers.create', 'vouchers.post',
            'sales.*', 'inventory.view', 'reports.view',
        ],
        'purchases' => [
            'company.view', 'masters.view', 'vouchers.view', 'vouchers.create', 'vouchers.post',
            'purchases.*', 'inventory.view', 'reports.view',
        ],
        'inventory' => ['company.view', 'masters.view', 'inventory.*', 'reports.view'],
        'employee' => ['company.view', 'payroll.*'],
        'user' => ['company.view', 'masters.view', 'vouchers.view', 'vouchers.create', 'reports.view'],
        'viewer' => ['*.view'],
    ],

    // Roles that always hold every permission, whatever the pivot says.
    'super_roles' => ['owner', 'admin'],

    // Classes with a seed(Company $company) method, run when a company is created.
    'company_seeders' => [
        \App\Services\Setup\DefaultChartOfAccounts::class,
        \App\Services\Setup\DefaultVoucherTypes::class,
    ],

    // Company-owned tables deleted (in this order) before the core tables when a company is deleted.
    // Value: self-referencing column to clear first, or null.
    'deletion_tables' => [
        'voucher_revisions' => null,
        'bill_allocations' => null,
        'voucher_entries' => null,
        'vouchers' => 'reversal_of_id',
        'voucher_types' => null,
    ],

    // Tables (=> column) that reference a ledger; a ledger used in any of them cannot be deleted.
    'ledger_usage' => [
        'voucher_entries' => 'account_id',
        'bill_allocations' => 'account_id',
        'vouchers' => 'party_account_id',
    ],

    // Base voucher types and their behaviour.
    'voucher_base_types' => [
        'journal' => 'Journal',
        'contra' => 'Contra',
        'payment' => 'Payment',
        'receipt' => 'Receipt',
        'sales' => 'Sales',
        'purchase' => 'Purchase',
        'credit_note' => 'Credit Note',
        'debit_note' => 'Debit Note',
    ],

    /*
    | Sidebar navigation. Items whose route is not registered yet are hidden,
    | and items the user lacks the permission for are hidden too.
    */
    'menu' => [
        ['label' => 'Dashboard', 'icon' => 'speedometer2', 'route' => 'dashboard'],
        ['heading' => 'Masters'],
        ['label' => 'Account Groups', 'icon' => 'diagram-3', 'route' => 'account-groups.index', 'can' => 'masters.view'],
        ['label' => 'Ledgers', 'icon' => 'journal-bookmark', 'route' => 'ledgers.index', 'can' => 'masters.view'],
        ['label' => 'Cost Centres', 'icon' => 'bullseye', 'route' => 'cost-centres.index', 'can' => 'masters.view'],
        ['label' => 'Voucher Types', 'icon' => 'ui-checks', 'route' => 'voucher-types.index', 'can' => 'voucher_types.view'],
        ['label' => 'Customers', 'icon' => 'person-badge', 'route' => 'customers.index', 'can' => 'sales.view'],
        ['label' => 'Suppliers', 'icon' => 'truck', 'route' => 'suppliers.index', 'can' => 'purchases.view'],
        ['label' => 'Stock Items', 'icon' => 'box-seam', 'route' => 'stock-items.index', 'can' => 'inventory.view'],
        ['heading' => 'Transactions'],
        ['label' => 'Vouchers', 'icon' => 'journal-text', 'route' => 'vouchers.index', 'can' => 'vouchers.view'],
        ['label' => 'Sales Invoices', 'icon' => 'receipt', 'route' => 'invoices.index', 'can' => 'sales.view', 'params' => ['kind' => 'sales']],
        ['label' => 'Purchase Bills', 'icon' => 'cart3', 'route' => 'invoices.index', 'can' => 'purchases.view', 'params' => ['kind' => 'purchase']],
        ['label' => 'Banking', 'icon' => 'bank', 'route' => 'bank-reconciliation.index', 'can' => 'banking.view'],
        ['heading' => 'Reports'],
        ['label' => 'Trial Balance', 'icon' => 'list-columns', 'route' => 'reports.trial-balance', 'can' => 'reports.view'],
        ['label' => 'Profit & Loss', 'icon' => 'graph-up-arrow', 'route' => 'reports.profit-loss', 'can' => 'reports.view'],
        ['label' => 'Balance Sheet', 'icon' => 'clipboard-data', 'route' => 'reports.balance-sheet', 'can' => 'reports.view'],
        ['label' => 'Day Book', 'icon' => 'calendar-day', 'route' => 'reports.day-book', 'can' => 'reports.view'],
        ['label' => 'Ledger Report', 'icon' => 'book', 'route' => 'reports.ledger', 'can' => 'reports.view'],
        ['label' => 'Outstanding', 'icon' => 'hourglass-split', 'route' => 'reports.outstanding', 'can' => 'reports.view'],
        ['heading' => 'Company'],
        ['label' => 'Company Settings', 'icon' => 'building-gear', 'route' => 'settings.edit', 'can' => 'company.edit'],
        ['label' => 'Financial Years', 'icon' => 'calendar-range', 'route' => 'financial-years.index', 'can' => 'periods.view'],
        ['label' => 'Users & Roles', 'icon' => 'people', 'route' => 'members.index', 'can' => 'users.view'],
        ['label' => 'Audit Trail', 'icon' => 'shield-check', 'route' => 'audit-logs.index', 'can' => 'audit.view'],
        ['label' => 'Companies', 'icon' => 'buildings', 'route' => 'companies.index'],
    ],

];
