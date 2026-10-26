# Implementation status

Legend: **Done** = working end to end with tests · **Partial** = usable but incomplete ·
**Missing** = not started. A route, menu item or placeholder does not count as done.

| Module | Status | Notes |
|---|---|---|
| A. Company & configuration | Done | Multi-company, switch, settings, GSTIN/PAN validation, FY + monthly period locks, users/roles per company, custom roles. |
| B. Accounting engine | Done (core) | Groups, ledgers, cost centres, voucher types/numbering, 8 voucher classes, bill-wise, draft/approval/post/cancel/reverse, revisions. Missing: opening bill-wise refs, recurring vouchers, multi-currency. |
| C. Sales | Partial | Done: customers, sales invoices with items and GST, credit notes against invoices, receivables with ageing, invoice print, credit-limit warning. Missing: quotations, sales orders, delivery notes, stock items on invoices. |
| D. Purchases | Partial | Done: suppliers, purchase bills (duplicate supplier bill check), debit notes, payables with ageing. Missing: purchase orders, GRN, stock items. |
| E. GST | Partial | CGST/SGST/IGST computed on invoices from place of supply and registration type; HSN-wise tax summary on print. Missing: GSTR-1/3B registers, cess, reverse charge, e-invoice, e-way bill. |
| F. Inventory | Missing | Phase 6. Closing stock is not valued in the P&L. |
| G. Banking | Partial | Bank ledgers with bank details, bank book, bank reconciliation with bank dates, CSV statement import with auto/manual matching. Missing: cheque register and printing, OFX/MT940 import. |
| H. Reports | Partial | Done: ledger, cash/bank book, day book, trial balance, group summary, P&L, balance sheet, dashboard, CSV. Receivables/payables with ageing. Missing: cash flow, ratios, GST reports, PDF/Excel. |
| I. Payroll | Missing | Phase 6. |
| J. Transport | Missing | Phase 7. |
| K. Security & audit | Done (core) | Permissions per company, audit trail with old/new values, login/logout/failed login logs, maker-checker. Missing: 2FA, IP restrictions. |
| L. Data management | Missing | Phase 8: backup/restore, import/export. |
| M. Dashboard & usability | Partial | Responsive layout, dashboard figures, Alt-key shortcuts. Missing: global search, configurable shortcuts. |

Testing: run `php artisan test` (SQLite in memory). The suite has not yet been run against MySQL.

Per-phase details and manual checklists: `docs/phase-*.md`.
