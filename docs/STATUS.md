# Implementation status

Legend: **Done** = working end to end with tests · **Partial** = usable but incomplete ·
**Missing** = not started. A route, menu item or placeholder does not count as done.

| Module | Status | Notes |
|---|---|---|
| A. Company & configuration | Done | Multi-company, switch, settings, GSTIN/PAN validation, FY + monthly period locks, users/roles per company, custom roles. |
| B. Accounting engine | Done (core) | Groups, ledgers, cost centres, voucher types/numbering, 8 voucher classes, bill-wise, draft/approval/post/cancel/reverse, revisions. Missing: opening bill-wise refs, recurring vouchers, multi-currency. |
| C. Sales | Partial | Done: customers, sales invoices with items and GST, credit notes against invoices, receivables with ageing, invoice print, credit-limit warning. Stock items on invoice lines. Missing: quotations, sales orders, delivery notes. |
| D. Purchases | Partial | Done: suppliers, purchase bills (duplicate supplier bill check), debit notes, payables with ageing. Stock items on bill lines. Missing: purchase orders, GRN. |
| E. GST | Partial | CGST/SGST/IGST on invoices from place of supply and registration type; GSTR-1 working papers (B2B, B2CL, B2CS, exports, CDNR/CDNUR, HSN, documents) with CSV; GSTR-3B with ITC and set-off; purchase register. Missing: JSON for the portal, GSTR-2B matching, cess, reverse charge, e-invoice, e-way bill. |
| F. Inventory | Partial | Done: units, stock groups and categories, godowns, stock items with opening stock, stock on invoices and returns, transfers and adjustments (stock journals), weighted average valuation, closing stock in P&L and balance sheet, stock summary, item movement ledger, reorder status, negative-stock warning. Missing: batches/serials, FIFO, orders and delivery notes/GRN, goods in transit, ageing, BOM. |
| G. Banking | Partial | Bank ledgers with bank details, bank book, bank reconciliation with bank dates, CSV statement import with auto/manual matching. Missing: cheque register and printing, OFX/MT940 import. |
| H. Reports | Partial | Done: ledger, cash/bank book, day book, trial balance, group summary, P&L, balance sheet, cash flow, stock summary, dashboard, CSV. Receivables/payables with ageing. GST returns. Missing: ratios, PDF/Excel. |
| I. Payroll | Partial | Done: employees, pay heads, salary structures, paid days, PF/ESI/professional tax/TDS, pay sheet, payslips, posting a salary journal, cancellation. Missing: attendance and leave registers, overtime, loans/advances, PF ECR and ESI files, Form 16, bank transfer file. |
| J. Transport | Missing | Phase 7. |
| K. Security & audit | Done (core) | Permissions per company, audit trail with old/new values, login/logout/failed login logs, maker-checker. Missing: 2FA, IP restrictions. |
| L. Data management | Missing | Phase 8: backup/restore, import/export. |
| M. Dashboard & usability | Partial | Responsive layout, dashboard figures, Alt-key shortcuts. Missing: global search, configurable shortcuts. |

Testing: run `php artisan test` (SQLite in memory). The suite has not yet been run against MySQL.

Per-phase details and manual checklists: `docs/phase-*.md`.
