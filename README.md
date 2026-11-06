# TallyERP

A Tally-style accounting app for Indian businesses, built with Laravel 12 and Bootstrap 5.

## Features

| Area | What it does |
|---|---|
| **Companies** | Several companies per user, GSTIN/PAN validation, financial years (e.g. `2026-27`) |
| **Masters** | Tally's 28 predefined account groups created for every company; your own sub-groups; ledgers with Dr/Cr opening balances |
| **Vouchers** | Payment, Receipt, Contra, Journal, Sales, Purchase, Debit/Credit Note. Debits must equal credits, numbering per type, dates within the financial year, cash/bank rules for contra/payment/receipt. Day Book, edit and delete |
| **GST** | GSTIN/state/HSN/rate on ledgers; sales and purchase invoices, credit and debit notes, split into CGST+SGST or IGST by place of supply; printable tax invoice; GSTR-1 (B2B, B2C, credit notes, HSN), GSTR-3B with ITC set-off, blocked credit and rule 42/43 reversal; e-invoice JSON (NIC schema 1.1) for B2B invoices and credit notes; e-way bill JSON for the portal's bulk upload |
| **Inventory** | Units, stock groups, stock items; invoice lines move stock; Stock Summary at weighted average cost; opening/closing stock in P&L and Balance Sheet; reorder levels with a Low Stock report and dashboard alert |
| **Estimates & orders** | Estimates/quotations, sale orders, purchase orders and delivery challans; convert any of them into a GST invoice in one click |
| **Discounts** | Line discount % on invoices, notes, estimates, orders and challans; a discount % on the whole invoice, taken off every line before GST |
| **Barcodes** | Item barcodes, scan-to-bill with a USB scanner or keyboard, printable Code 128 label sheets |
| **Batches & serials** | Batch numbers with manufacturing/expiry dates and a batch-wise stock report; serial/IMEI numbers tracked from purchase to sale with a lookup page |
| **Price lists** | Wholesale, retail, dealer (any number of) rate lists; assign one to a party and its rates fill in on invoices |
| **Invoice printing** | Several invoice designs, amount in words, bank details, UPI payment QR code, terms; print or save as PDF; 80 mm thermal receipt |
| **WhatsApp & SMS** | Share an invoice on WhatsApp with a secure public link; one-click payment reminders from Receivables; send the same invoice and reminder messages by SMS |
| **Manufacturing** | Bills of materials and production entries: raw materials consumed, finished goods added at cost (materials + other costs) |
| **Loans** | Loans taken with EMI calculation and a full repayment schedule; paying an EMI splits principal (reduces the loan) and interest (expense) |
| **Mobile app** | Installable on phones and desktops as a Progressive Web App, with an offline page |
| **Reports** | Trial Balance, Profit & Loss, Balance Sheet, Ledger statement, Receivables/Payables with ageing, Cost Centres, Budget vs Actual |
| **Banking** | Bank reconciliation with cheque/UTR numbers and cleared dates; import a bank statement CSV to match and clear entries automatically; cheque register (issued/received, pending/cleared/bounced) where a bounced cheque is reversed by a journal |
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

`tests/Feature/BulkEntriesTest.php` is a load test: it posts 5,000 entries (sales and purchase invoices with line and bill discounts, receipts, cheques and bounces, payments, contras, journals, loan EMIs) through the real controllers, then checks that every voucher balances, invoice totals match their lines, the trial balance agrees, stock never goes negative and every report opens. It takes about 2–3 minutes.

```bash
php artisan test --exclude-group=bulk   # everything except the load test (fast)
php artisan test --group=bulk           # only the 5,000-entry load test
```

### Test data

```bash
php artisan db:seed --class=DemoSeeder        # small demo company
php artisan db:seed --class=BulkDataSeeder    # "Bulk Test Traders" with 5,000 entries
BULK_ENTRIES=20000 php artisan db:seed --class=BulkDataSeeder   # any size
```

Log in as `demo@example.com` / `password`. Bulk data has 60 customers (Customer 01 uses the test number +919660533307), 20 suppliers, 40 barcoded items, two loans and cheques. WhatsApp and SMS stay in test mode (dummy keys), so nothing is sent.

## Simplifications

- ITC reversal covers rules 42 and 43 (common inputs and capital goods, 0% sales treated as exempt), month by month.
- Payroll uses common PF/ESI rules and a flat professional tax of 200 above 10,000; adjust `app/Support/Payroll.php` for your state.
- GST invoices, TDS bills and payroll journals are deleted and re-entered rather than edited.
- e-invoice JSON is generated for upload to the e-invoice portal; the app does not call the IRP API or store the IRN/QR code. E-way bill JSON is likewise uploaded on the portal; the EWB number is then entered on the invoice (not fetched automatically).
- Multi-currency: GST invoices are in rupees; an opening balance without a foreign amount is not revalued.
- WhatsApp: "Send on WhatsApp" opens wa.me with the message ready; "Send via API" uses the WhatsApp Business (Cloud) API configured by `WHATSAPP_*` in `.env`. With the dummy keys in `.env.example` messages are only written to `storage/logs` (test mode); set `WHATSAPP_DRIVER=cloud` with a real token and phone number ID to send. Messages to customers who haven't written in the last 24 hours need an approved template (`WHATSAPP_TEMPLATE`).
- SMS: sent through an HTTP SMS gateway configured by `SMS_*` in `.env` (bearer API key; JSON `sender`, `to`, `message`, `template_id`). Dummy keys = test mode, messages go to `storage/logs`. Set `SMS_DRIVER=http` with your gateway's URL and key; Indian SMS also needs a DLT-registered sender ID and template (`SMS_DLT_TEMPLATE_ID`). Adjust `app/Support/Sms.php` if your gateway expects different fields.
- Loans: interest on an EMI is entered as paid (pre-filled from the outstanding balance); prepayments simply reduce the outstanding, the schedule stays on the original terms.
