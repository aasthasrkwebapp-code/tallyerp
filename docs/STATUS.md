# Implementation status

Legend: **Done** = working end to end with tests · **Partial** = usable but incomplete ·
**Missing** = not started. A route, menu item or placeholder does not count as done.

| Module | Status | Notes |
|---|---|---|
| A. Company & configuration | Done | Multi-company, switch, settings, GSTIN/PAN validation, FY + monthly period locks, users/roles per company, custom roles. Logo upload: missing. |
| B. Accounting engine | Done (core) | Groups, ledgers, cost centres, voucher types/numbering, 8 voucher classes, bill-wise, draft/approval/post/cancel/reverse, revisions. Missing: opening bill-wise refs, recurring vouchers, multi-currency. |
| C. Sales | Missing | Phase 3: customers, sales invoices with items and tax, credit notes, receivables. (Sales vouchers can already be posted through the voucher engine.) |
| D. Purchases | Missing | Phase 3. |
| E. GST | Missing | Phase 5. GST rates/HSN fields exist on ledgers only. |
| F. Inventory | Missing | Phase 6. Closing stock is not valued in the P&L. |
| G. Banking | Partial | Bank ledgers with bank details, bank book. Missing: reconciliation, statement import, cheque register. |
| H. Reports | Partial | Done: ledger, cash/bank book, day book, trial balance, group summary, P&L, balance sheet, dashboard, CSV. Missing: outstanding/ageing, cash flow, ratios, GST reports, PDF/Excel. |
| I. Payroll | Missing | Phase 6. |
| J. Transport | Missing | Phase 7. |
| K. Security & audit | Done (core) | Permissions per company, audit trail with old/new values, login/logout/failed login logs, maker-checker. Missing: 2FA, IP restrictions. |
| L. Data management | Missing | Phase 8: backup/restore, import/export. |
| M. Dashboard & usability | Partial | Responsive layout, dashboard figures, Alt-key shortcuts. Missing: global search, configurable shortcuts. |

Testing: run `php artisan test` (SQLite in memory). The suite has not yet been run against MySQL.

Per-phase details and manual checklists: `docs/phase-*.md`.
