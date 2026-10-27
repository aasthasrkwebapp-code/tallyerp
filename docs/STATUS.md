# Implementation status

Legend: **Done** = working end to end with tests · **Partial** = usable but incomplete ·
**Missing** = not started. A route, menu item or placeholder does not count as done.

| Module | Status | Notes |
|---|---|---|
| A. Company & configuration | Done | Multi-company, switch, settings, GSTIN/PAN validation, FY + monthly period locks, users/roles per company, custom roles. |
| B. Accounting engine | Done (core) | Groups, ledgers, cost centres, voucher types/numbering, 8 voucher classes, bill-wise, draft/approval/post/cancel/reverse, revisions. Missing: opening bill-wise refs, recurring vouchers, multi-currency. |
| C. Sales | Partial | Done: customers, sales invoices with items and GST, credit notes against invoices, receivables with ageing, invoice print and PDF, credit-limit warning. Stock items on invoice lines. Sales orders with invoices against them (part delivery), close/cancel and a pending orders report. Delivery notes that move stock, with invoices made from them and a delivered-not-invoiced report. Quotations with validity and conversion to sales orders. Missing: advances against orders, quotation revisions. |
| D. Purchases | Partial | Done: suppliers, purchase bills (duplicate supplier bill check), debit notes, payables with ageing. Stock items on bill lines. Purchase orders with bills against them, close/cancel and a pending orders report. Goods receipt notes with bills made from them and a received-not-billed report. Missing: GRNI provision in the accounts. |
| E. GST | Partial | CGST/SGST/IGST on invoices from place of supply and registration type; GSTR-1 working papers (B2B, B2CL, B2CS, exports, CDNR/CDNUR, HSN, documents) with CSV; GSTR-3B with ITC and set-off; purchase register. Missing: JSON for the portal, GSTR-2B matching, cess, reverse charge, e-invoice, e-way bill. |
| F. Inventory | Partial | Done: units, stock groups and categories, godowns, stock items with opening stock, stock on invoices and returns, transfers and adjustments (stock journals), weighted average valuation, closing stock in P&L and balance sheet, stock summary, item movement ledger, reorder status, negative-stock warning. Batch numbers with expiry and serial numbers on invoices, returns, stock journals and opening stock, with batch/expiry and serial reports. Delivery notes and GRNs move stock. Missing: FIFO, goods in transit, ageing, BOM, batches on import. |
| G. Banking | Partial | Bank ledgers with bank details, bank book, bank reconciliation with bank dates, CSV statement import with auto/manual matching. Missing: cheque register and printing, OFX/MT940 import. |
| H. Reports | Partial | Done: ledger, cash/bank book, day book, trial balance, group summary, P&L, balance sheet, cash flow, stock summary, dashboard, CSV. Receivables/payables with ageing. GST returns. CSV, Excel and PDF export on every report including P&L and balance sheet. Missing: ratios, funds flow, budgets, period comparison. |
| I. Payroll | Partial | Done: employees, pay heads, salary structures, paid days, PF/ESI/professional tax/TDS, pay sheet, payslips, posting a salary journal, cancellation. Missing: attendance and leave registers, overtime, loans/advances, PF ECR and ESI files, Form 16, bank transfer file. |
| J. Transport | Partial | Done: vehicles, drivers with advance ledgers, routes, trips, expenses posting vouchers, freight bills as sales invoices, trip/vehicle/route/customer/driver reports. Missing: LR printing, e-way bill numbers, multi-trip bills, document expiry reminders, tyre tracking. |
| K. Security & audit | Done (core) | Permissions per company, audit trail with old/new values, login/logout/failed login logs, maker-checker. Missing: 2FA, IP restrictions. |
| L. Data management | Partial | Done: company backups with checksums, verify, restore with safety backup, scheduled backups, integrity checks; CSV/Excel import of ledgers, parties, stock items and vouchers with preview, error report and duplicate detection; CSV/Excel export of masters, vouchers and every report. Missing: invoice import, Tally XML, off-site/encrypted backups, API sync. |
| M. Dashboard & usability | Partial | Responsive layout, dashboard figures, Alt-key shortcuts. Global search across ledgers, items, batches/serials, invoices, vouchers, orders, notes, employees and trips (permission-aware, `/` or Ctrl+K). Missing: search-as-you-type dropdown, configurable shortcuts. |

Testing: run `php artisan test` (SQLite in memory by default). The full suite also passes on MySQL 8.0 (set `DB_CONNECTION=mysql` and the `DB_*` variables), and every migration rolls back cleanly on both. `.github/workflows/tests.yml` runs PHP 8.2 and 8.3 against SQLite and MySQL.

Per-phase details and manual checklists: `docs/phase-*.md`.
