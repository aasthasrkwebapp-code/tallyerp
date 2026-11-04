# TallyERP

A Tally-style accounting app for Indian businesses, built with Laravel 12 and Bootstrap 5.

## Features

| Area | What it does |
|---|---|
| **Companies** | Several companies per user, GSTIN/PAN validation, financial years (e.g. `2026-27`) |
| **Masters** | Tally's 28 predefined account groups created for every company; your own sub-groups; ledgers with Dr/Cr opening balances |
| **Vouchers** | Payment, Receipt, Contra, Journal, Sales, Purchase, Debit/Credit Note. Debits must equal credits, numbering per type, dates within the financial year, cash/bank rules for contra/payment/receipt. Day Book, edit and delete |
| **GST** | GSTIN/state/HSN/rate on ledgers; sales and purchase invoices, credit and debit notes, split into CGST+SGST or IGST by place of supply; printable tax invoice; GSTR-1 (B2B, B2C, credit notes, HSN), GSTR-3B with ITC set-off and blocked credit; e-invoice JSON (NIC schema 1.1) for B2B invoices and credit notes |
| **Inventory** | Units, stock groups, stock items; invoice lines move stock; Stock Summary at weighted average cost; opening/closing stock in P&L and Balance Sheet |
| **Reports** | Trial Balance, Profit & Loss, Balance Sheet, Ledger statement, Receivables/Payables with ageing, Cost Centres, Budget vs Actual |
| **Banking** | Bank reconciliation with cheque/UTR numbers and cleared dates; import a bank statement CSV to match and clear entries automatically |
| **TDS** | TDS sections on suppliers (194C/H/I/J/Q, 20% without PAN), bills booked net of TDS, TDS report and balance payable |
| **Payroll** | Employees, monthly runs with PF/ESI/professional tax, salary journal posted automatically, payslips and register |
| **Year-end** | Move between financial years; assets/liabilities carry forward, income/expenses restart, earlier profit shown in P&L A/c |
| **Users & roles** | Share a company with other users as admin, accountant, sales, purchases, inventory, employee or viewer |
| **Dashboards** | Company KPIs (cash & bank, receivables, payables, sales, profit, stock, GST), monthly sales chart, recent vouchers |
| **Export** | Every report downloads as a CSV that opens in Excel |
| **Audit log** | Who created, changed or deleted what, with before/after values |
| **Budgets** | Yearly budgets per income/expense ledger and a Budget vs Actual report |
| **Multi-currency** | Foreign currencies, foreign-currency ledgers, vouchers in amount × rate, foreign amounts on ledger statements, forex revaluation with exchange gain/loss posting |
| **Backups** | Download a company as a JSON file and restore it as a new company |

Amounts are shown in Indian format (1,15,000.00).

## Setup

Requires PHP 8.2+, Composer and Node.js.

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate --seed   # creates tables and the roles
npm install && npm run build
php artisan serve
```

Then open http://localhost:8000, register, and create your first company.

## Tests

```bash
php artisan test
```

Feature tests cover each module with worked examples (see `tests/Feature`).

## Simplifications

- ITC reversals (e.g. rule 42/43 for exempt supplies) are not calculated.
- Payroll uses common PF/ESI rules and a flat professional tax of 200 above 10,000; adjust `app/Support/Payroll.php` for your state.
- GST invoices, TDS bills and payroll journals are deleted and re-entered rather than edited.
- e-invoice JSON is generated for upload to the e-invoice portal; the app does not call the IRP API or store the IRN/QR code. E-way bills are not generated.
- Multi-currency: ledger opening balances are in rupees only and are not revalued; GST invoices are in rupees.
