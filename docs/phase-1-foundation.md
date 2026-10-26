# Phase 1 — Foundation

Covers: project setup, authentication, company configuration, responsive layout,
permissions, financial years and period locks, audit trail.

## What was built

| Area | Implementation |
|------|----------------|
| Layout | `layouts/erp.blade.php`: off-canvas drawer below 992px, collapsible sidebar on large screens (remembered in the browser), sticky top bar with company switcher and user menu, skip link, keyboard shortcuts (Alt+key). Tables use `.table-stack` to become cards on phones. |
| Auth | Breeze controllers kept; all views rewritten in Bootstrap. Email verification enforced (configurable with `ERP_REQUIRE_EMAIL_VERIFICATION`). |
| Companies | Create, view, edit, delete (type name to confirm), switch active company. GSTIN, PAN, TAN, CIN, PIN validation, GSTIN-vs-PAN and GSTIN-vs-state checks. |
| Settings | Profile tab and Preferences tab (bill-wise, cost centres, inventory, GST, future-dated vouchers, maker-checker approval, invoice terms/footer, logo upload). |
| Financial years | Created automatically with the company (Indian April–March year) plus 12 monthly accounting periods. Open next year, set current year, lock/unlock a year or individual month. |
| Users & roles | Add registered users to a company with a role, change role, deactivate, remove. Last active owner is protected. Custom roles with a permission matrix; system roles read-only. |
| Audit | `audit_logs` with old/new values, user, IP, browser. Filterable list and detail screen. |

## Manual testing checklist

1. Register a new account, verify email (or set `ERP_REQUIRE_EMAIL_VERIFICATION=false`).
2. You are redirected to **Create company**. Enter an invalid GSTIN — an error is shown.
3. Create a company with GSTIN `27ABCDE1234F1Z5`, PAN `ABCDE1234F`, state Maharashtra.
4. Dashboard shows the company name and FY. Open **Financial Years** — 12 months listed.
5. Lock April; the tile turns red. Unlock it again.
6. Open **Company Settings**, change the name, save. Open **Audit Trail** — the change shows old and new values.
7. Register a second account in another browser. As owner, add it under **Users & Roles** as *Viewer*.
8. Sign in as the viewer: Settings is hidden from the menu and `/settings` returns 403.
9. Try to change your own (only owner) role to Viewer — it is refused.
10. Resize the browser to 360px wide: the sidebar becomes a drawer, tables become cards, no horizontal scroll.
11. Create a second company; use **Switch** in the top bar to move between them.

## Automated tests

`tests/Feature/Company/*`, `tests/Feature/PagesRenderTest.php`, Breeze auth tests.
