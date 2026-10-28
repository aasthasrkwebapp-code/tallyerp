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
        'transport' => ['company.view', 'masters.view', 'transport.*', 'reports.view'],
        'user' => ['company.view', 'masters.view', 'vouchers.view', 'vouchers.create', 'reports.view'],
        'viewer' => ['*.view'],
    ],

    // Roles that always hold every permission, whatever the pivot says.
    'super_roles' => ['owner', 'admin'],

    // Classes with a seed(Company $company) method, run when a company is created.
    'company_seeders' => [
        \App\Services\Setup\DefaultChartOfAccounts::class,
        \App\Services\Setup\DefaultVoucherTypes::class,
        \App\Services\Setup\DefaultInventory::class,
        \App\Services\Setup\DefaultPayroll::class,
    ],

    // Company-owned tables deleted (in this order) before the core tables when a company is deleted.
    // Value: self-referencing column to clear first, or null.
    // Where company backups are written (Data → Backup & Restore).
    'backup_path' => env('ERP_BACKUP_PATH', storage_path('app/private/backups')),

    'deletion_tables' => [
        'transport_expenses' => null,
        'trips' => null,
        'transport_routes' => null,
        'drivers' => null,
        'vehicles' => null,
        'loan_recoveries' => null,
        'employee_loans' => null,
        'attendance_days' => null,
        'attendance_months' => null,
        'leave_types' => null,
        'payslip_lines' => null,
        'payslips' => null,
        'payroll_runs' => null,
        'employee_pay_heads' => null,
        'pay_heads' => null,
        'employees' => null,
        'bank_statement_lines' => null,
        'invoice_items' => null,
        'invoices' => 'original_invoice_id',
        'delivery_note_items' => null,
        'delivery_notes' => null,
        'order_items' => null,
        'orders' => 'source_id',
        'parties' => null,
        'bom_components' => null,
        'stock_journal_lines' => null,
        'stock_journals' => null,
        'stock_items' => null,
        'godowns' => null,
        'stock_categories' => null,
        'stock_groups' => 'parent_id',
        'units' => null,
        'budget_lines' => null,
        'budgets' => null,
        'spoilt_cheques' => null,
        'cheque_books' => null,
        'recurring_voucher_runs' => null,
        'recurring_voucher_lines' => null,
        'recurring_vouchers' => null,
        'voucher_revisions' => null,
        'bill_allocations' => null,
        'voucher_entries' => null,
        'vouchers' => 'reversal_of_id',
        'voucher_types' => null,
    ],

    // Tables (=> column or columns) that reference a ledger; a ledger used in any of them cannot be deleted.
    'ledger_usage' => [
        'voucher_entries' => 'account_id',
        'recurring_voucher_lines' => 'account_id',
        'budget_lines' => 'account_id',
        'bill_allocations' => 'account_id',
        'vouchers' => ['party_account_id', 'instrument_account_id'],
        'cheque_books' => 'account_id',
        'invoices' => 'party_account_id',
        'orders' => 'party_account_id',
        'delivery_notes' => 'party_account_id',
        'invoice_items' => 'account_id',
        'bank_statement_lines' => 'account_id',
        'stock_items' => ['sales_account_id', 'purchase_account_id'],
        'pay_heads' => ['account_id', 'payable_account_id'],
        'employee_loans' => ['account_id', 'paid_account_id'],
        'drivers' => 'account_id',
        'trips' => 'party_account_id',
        'transport_expenses' => ['expense_account_id', 'paid_account_id'],
    ],

    /*
    | Transport expense categories and the ledger each posts to by default
    | (created under Direct Expenses the first time it is used).
    */
    'transport_expense_categories' => [
        'fuel' => ['label' => 'Fuel', 'ledger' => 'Fuel Expenses'],
        'toll' => ['label' => 'Toll & parking', 'ledger' => 'Toll Charges'],
        'driver' => ['label' => 'Driver allowance (bata)', 'ledger' => 'Driver Allowance'],
        'loading' => ['label' => 'Loading & unloading', 'ledger' => 'Loading & Unloading Charges'],
        'maintenance' => ['label' => 'Repairs & maintenance', 'ledger' => 'Vehicle Repairs & Maintenance'],
        'tyres' => ['label' => 'Tyres', 'ledger' => 'Vehicle Repairs & Maintenance'],
        'statutory' => ['label' => 'Insurance, permit & tax', 'ledger' => 'Vehicle Insurance & Taxes'],
        'hire' => ['label' => 'Hired vehicle charges', 'ledger' => 'Lorry Hire Charges'],
        'other' => ['label' => 'Other', 'ledger' => 'Other Trip Expenses'],
    ],

    /*
    | Payroll statutory limits (monthly, rupees). PF is worked out on basic
    | salary up to the ceiling; ESI applies while gross pay is within the limit.
    | Professional tax slabs: [gross up to (null = above), tax, February tax].
    */
    'payroll' => [
        'pf_wage_ceiling' => 15000,
        'esi_wage_limit' => 21000,
        'professional_tax' => [
            'Maharashtra' => [[7500, 0], [10000, 175], [null, 200, 300]],
            'Karnataka' => [[24999, 0], [null, 200]],
            'West Bengal' => [[10000, 0], [15000, 110], [25000, 130], [40000, 150], [null, 200]],
            'Gujarat' => [[11999, 0], [null, 200]],
            'Telangana' => [[15000, 0], [20000, 150], [null, 200]],
        ],
    ],

    // Registration types of customers and suppliers under GST.
    'gst_registration_types' => [
        'regular' => 'Regular',
        'composition' => 'Composition',
        'unregistered' => 'Unregistered',
        'consumer' => 'Consumer',
        'overseas' => 'Overseas',
        'sez' => 'SEZ',
    ],

    'gst_rates' => ['0', '0.1', '0.25', '1', '1.5', '3', '5', '6', '7.5', '12', '18', '28', '40'],

    // Invoice kinds: voucher class, permission module, party group and item ledger type.
    'invoice_kinds' => [
        'sales' => ['label' => 'Sales Invoice', 'plural' => 'Sales Invoices', 'base' => 'sales', 'module' => 'sales', 'party_group' => 'sundry_debtors', 'item_type' => 'sales', 'tax' => 'output'],
        'credit_note' => ['label' => 'Credit Note', 'plural' => 'Credit Notes (Sales Returns)', 'base' => 'credit_note', 'module' => 'sales', 'party_group' => 'sundry_debtors', 'item_type' => 'sales', 'tax' => 'output'],
        'purchase' => ['label' => 'Purchase Bill', 'plural' => 'Purchase Bills', 'base' => 'purchase', 'module' => 'purchases', 'party_group' => 'sundry_creditors', 'item_type' => 'purchase', 'tax' => 'input'],
        'debit_note' => ['label' => 'Debit Note', 'plural' => 'Debit Notes (Purchase Returns)', 'base' => 'debit_note', 'module' => 'purchases', 'party_group' => 'sundry_creditors', 'item_type' => 'purchase', 'tax' => 'input'],
    ],

    // Sales and purchase orders: the invoice kind that fulfils each.
    'order_kinds' => [
        'quotation' => ['label' => 'Quotation', 'plural' => 'Quotations', 'prefix' => 'QT', 'invoice_kind' => 'sales', 'module' => 'sales', 'party_group' => 'sundry_debtors', 'quotation' => true, 'converts_to' => 'sales_order', 'date_label' => 'Valid until'],
        'sales_order' => ['label' => 'Sales Order', 'plural' => 'Sales Orders', 'prefix' => 'SO', 'invoice_kind' => 'sales', 'module' => 'sales', 'party_group' => 'sundry_debtors'],
        'purchase_order' => ['label' => 'Purchase Order', 'plural' => 'Purchase Orders', 'prefix' => 'PO', 'invoice_kind' => 'purchase', 'module' => 'purchases', 'party_group' => 'sundry_creditors'],
    ],

    // Delivery notes and goods receipt notes: the order and invoice kinds they sit between.
    'note_kinds' => [
        'delivery_note' => ['label' => 'Delivery Note', 'plural' => 'Delivery Notes', 'prefix' => 'DN', 'direction' => 'out', 'order_kind' => 'sales_order', 'invoice_kind' => 'sales', 'module' => 'sales', 'party_group' => 'sundry_debtors'],
        'receipt_note' => ['label' => 'Goods Receipt Note', 'plural' => 'Goods Receipt Notes', 'prefix' => 'GRN', 'direction' => 'in', 'order_kind' => 'purchase_order', 'invoice_kind' => 'purchase', 'module' => 'purchases', 'party_group' => 'sundry_creditors'],
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
        ['label' => 'Units, Groups & Godowns', 'icon' => 'boxes', 'route' => 'inventory-masters.index', 'can' => 'inventory.view', 'params' => ['master' => 'units']],
        ['heading' => 'Transactions'],
        ['label' => 'Vouchers', 'icon' => 'journal-text', 'route' => 'vouchers.index', 'can' => 'vouchers.view'],
        ['label' => 'Recurring Vouchers', 'icon' => 'arrow-repeat', 'route' => 'recurring-vouchers.index', 'can' => 'vouchers.view'],
        ['label' => 'Quotations', 'icon' => 'chat-square-quote', 'route' => 'orders.index', 'can' => 'sales.view', 'params' => ['kind' => 'quotation']],
        ['label' => 'Sales Orders', 'icon' => 'clipboard-plus', 'route' => 'orders.index', 'can' => 'sales.view', 'params' => ['kind' => 'sales_order']],
        ['label' => 'Delivery Notes', 'icon' => 'box-arrow-right', 'route' => 'notes.index', 'can' => 'sales.view', 'params' => ['kind' => 'delivery_note']],
        ['label' => 'Sales Invoices', 'icon' => 'receipt', 'route' => 'invoices.index', 'can' => 'sales.view', 'params' => ['kind' => 'sales']],
        ['label' => 'Credit Notes', 'icon' => 'arrow-return-left', 'route' => 'invoices.index', 'can' => 'sales.view', 'params' => ['kind' => 'credit_note']],
        ['label' => 'Purchase Orders', 'icon' => 'clipboard-check', 'route' => 'orders.index', 'can' => 'purchases.view', 'params' => ['kind' => 'purchase_order']],
        ['label' => 'Goods Receipt Notes', 'icon' => 'box-arrow-in-left', 'route' => 'notes.index', 'can' => 'purchases.view', 'params' => ['kind' => 'receipt_note']],
        ['label' => 'Purchase Bills', 'icon' => 'cart3', 'route' => 'invoices.index', 'can' => 'purchases.view', 'params' => ['kind' => 'purchase']],
        ['label' => 'Debit Notes', 'icon' => 'arrow-return-right', 'route' => 'invoices.index', 'can' => 'purchases.view', 'params' => ['kind' => 'debit_note']],
        ['label' => 'Stock Journals', 'icon' => 'arrow-left-right', 'route' => 'stock-journals.index', 'can' => 'inventory.view'],
        ['label' => 'Banking', 'icon' => 'bank', 'route' => 'bank-reconciliation.index', 'can' => 'banking.view'],
        ['label' => 'Cheque Register', 'icon' => 'credit-card-2-front', 'route' => 'cheques.index', 'can' => 'banking.view'],
        ['label' => 'Cheque Books', 'icon' => 'journal-bookmark', 'route' => 'cheque-books.index', 'can' => 'banking.view'],
        ['heading' => 'Reports'],
        ['label' => 'Trial Balance', 'icon' => 'list-columns', 'route' => 'reports.trial-balance', 'can' => 'reports.view'],
        ['label' => 'Profit & Loss', 'icon' => 'graph-up-arrow', 'route' => 'reports.profit-loss', 'can' => 'reports.view'],
        ['label' => 'Balance Sheet', 'icon' => 'clipboard-data', 'route' => 'reports.balance-sheet', 'can' => 'reports.view'],
        ['label' => 'Cash Flow', 'icon' => 'water', 'route' => 'reports.cash-flow', 'can' => 'reports.view'],
        ['label' => 'Funds Flow', 'icon' => 'arrow-down-up', 'route' => 'reports.funds-flow', 'can' => 'reports.view'],
        ['label' => 'Ratio Analysis', 'icon' => 'percent', 'route' => 'reports.ratios', 'can' => 'reports.view'],
        ['label' => 'Period Comparison', 'icon' => 'layout-three-columns', 'route' => 'reports.comparison', 'can' => 'reports.view'],
        ['label' => 'Budgets & Variance', 'icon' => 'piggy-bank', 'route' => 'budgets.index', 'can' => 'reports.view'],
        ['label' => 'Day Book', 'icon' => 'calendar-day', 'route' => 'reports.day-book', 'can' => 'reports.view'],
        ['label' => 'Ledger Report', 'icon' => 'book', 'route' => 'reports.ledger', 'can' => 'reports.view'],
        ['label' => 'Cash / Bank Book', 'icon' => 'cash-stack', 'route' => 'reports.ledger', 'can' => 'reports.view', 'params' => ['book' => 'cash']],
        ['label' => 'Receivables', 'icon' => 'hourglass-split', 'route' => 'reports.outstanding', 'can' => 'reports.view', 'params' => ['type' => 'receivable']],
        ['label' => 'Payables', 'icon' => 'hourglass-bottom', 'route' => 'reports.outstanding', 'can' => 'reports.view', 'params' => ['type' => 'payable']],
        ['label' => 'Pending Sales Orders', 'icon' => 'hourglass', 'route' => 'orders.pending', 'can' => 'sales.view', 'params' => ['kind' => 'sales_order']],
        ['label' => 'Pending Purchase Orders', 'icon' => 'hourglass-top', 'route' => 'orders.pending', 'can' => 'purchases.view', 'params' => ['kind' => 'purchase_order']],
        ['label' => 'Delivered, Not Invoiced', 'icon' => 'box-arrow-right', 'route' => 'notes.pending', 'can' => 'sales.view', 'params' => ['kind' => 'delivery_note']],
        ['label' => 'Received, Not Billed', 'icon' => 'box-arrow-in-left', 'route' => 'notes.pending', 'can' => 'purchases.view', 'params' => ['kind' => 'receipt_note']],
        ['label' => 'GRNI Provision', 'icon' => 'journal-arrow-down', 'route' => 'grni.index', 'can' => 'purchases.view'],
        ['label' => 'Stock Summary', 'icon' => 'clipboard-check', 'route' => 'stock-summary', 'can' => 'inventory.view'],
        ['label' => 'Reorder Status', 'icon' => 'arrow-repeat', 'route' => 'stock-reorder', 'can' => 'inventory.view'],
        ['label' => 'Stock Ageing', 'icon' => 'calendar-range', 'route' => 'stock-ageing', 'can' => 'inventory.view'],
        ['label' => 'Goods in Transit', 'icon' => 'truck', 'route' => 'stock-transit', 'can' => 'inventory.view'],
        ['label' => 'Batches & Expiry', 'icon' => 'calendar2-x', 'route' => 'stock-batches', 'can' => 'inventory.view'],
        ['label' => 'Serial Numbers', 'icon' => 'upc-scan', 'route' => 'stock-serials', 'can' => 'inventory.view'],
        ['heading' => 'GST'],
        ['label' => 'GSTR-1', 'icon' => 'file-earmark-arrow-up', 'route' => 'gst.gstr1', 'can' => 'gst.view'],
        ['label' => 'GSTR-3B', 'icon' => 'file-earmark-text', 'route' => 'gst.gstr3b', 'can' => 'gst.view'],
        ['label' => 'Purchase Register', 'icon' => 'file-earmark-arrow-down', 'route' => 'gst.purchase-register', 'can' => 'gst.view'],
        ['heading' => 'Transport'],
        ['label' => 'Trips', 'icon' => 'signpost-split', 'route' => 'trips.index', 'can' => 'transport.view'],
        ['label' => 'Trip & Vehicle Expenses', 'icon' => 'fuel-pump', 'route' => 'transport-expenses.index', 'can' => 'transport.view'],
        ['label' => 'Vehicles, Drivers & Routes', 'icon' => 'truck-front', 'route' => 'transport-masters.index', 'can' => 'transport.view', 'params' => ['master' => 'vehicles']],
        ['label' => 'Transport Reports', 'icon' => 'bar-chart-line', 'route' => 'transport-reports', 'can' => 'transport.view', 'params' => ['report' => 'vehicles']],
        ['heading' => 'Payroll'],
        ['label' => 'Employees', 'icon' => 'person-vcard', 'route' => 'employees.index', 'can' => 'payroll.view'],
        ['label' => 'Pay Heads', 'icon' => 'list-check', 'route' => 'pay-heads.index', 'can' => 'payroll.view'],
        ['label' => 'Attendance', 'icon' => 'calendar-check', 'route' => 'attendance.index', 'can' => 'payroll.view'],
        ['label' => 'Leave Register', 'icon' => 'calendar2-week', 'route' => 'leave-register', 'can' => 'payroll.view'],
        ['label' => 'Loans & Advances', 'icon' => 'cash-coin', 'route' => 'employee-loans.index', 'can' => 'payroll.view'],
        ['label' => 'Monthly Payroll', 'icon' => 'wallet2', 'route' => 'payroll-runs.index', 'can' => 'payroll.view'],
        ['heading' => 'Company'],
        ['label' => 'Company Settings', 'icon' => 'building-gear', 'route' => 'settings.edit', 'can' => 'company.edit'],
        ['label' => 'Financial Years', 'icon' => 'calendar-range', 'route' => 'financial-years.index', 'can' => 'periods.view'],
        ['label' => 'Users & Roles', 'icon' => 'people', 'route' => 'members.index', 'can' => 'users.view'],
        ['label' => 'Audit Trail', 'icon' => 'shield-check', 'route' => 'audit-logs.index', 'can' => 'audit.view'],
        ['label' => 'Companies', 'icon' => 'buildings', 'route' => 'companies.index'],
        ['heading' => 'Data'],
        ['label' => 'Backup & Restore', 'icon' => 'database-check', 'route' => 'backups.index', 'can' => 'data.backup'],
        ['label' => 'Import', 'icon' => 'box-arrow-in-down', 'route' => 'imports.index', 'can' => 'data.import'],
        ['label' => 'Export', 'icon' => 'box-arrow-up', 'route' => 'exports.index', 'can' => 'data.export'],
        ['label' => 'Integrity Check', 'icon' => 'clipboard2-check', 'route' => 'integrity', 'can' => 'data.view'],
    ],

];
