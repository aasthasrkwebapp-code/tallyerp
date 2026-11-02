# Phase 10e: global search

## What it does

- A **search box** in the top bar searches the current company. On phones it is a search icon that opens the search page.
  - Press <kbd>/</kbd> or <kbd>Ctrl</kbd>+<kbd>K</kbd> anywhere to jump to it.
- The **search page** (`/search?q=…`) shows matches in groups, up to 8 in each group.
  - When a group has more, it says so and, where the list page has its own search, links to "See all matches".
- **What is searched**:

| Group | Matches on | Opens | Needs |
|---|---|---|---|
| Ledgers | name, alias, code, party GSTIN | ledger page | `masters.view` |
| Stock items | name, alias, code, HSN | item page | `inventory.view` |
| Batches & serial numbers | batch numbers and serial numbers on invoices, stock journals, delivery notes and opening stock | batch report for the item, or serial report with history | `inventory.view` |
| Invoices & notes | invoice number, party name, reference, narration | invoice page | `sales.view` or `purchases.view`, for that kind |
| Vouchers | voucher number, reference, narration. Vouchers of invoices you can see are left out, so they are not listed twice. | voucher page | `vouchers.view` |
| Quotations & orders | number, party name, reference | order page | `sales.view` or `purchases.view`, for that kind |
| Delivery & receipt notes | number, party name, reference | note page | `sales.view` or `purchases.view`, for that kind |
| Employees | name, code, PAN, phone, email | employee page | `payroll.view` |
| Trips | trip number, LR number, origin, destination | trip page | `transport.view` |

- Only the current company is searched. Every model is company-scoped.
- The term needs at least 2 characters and is matched anywhere in the field, ignoring case.
  - `%` and `_` are treated as literal characters.
- Asking for JSON (`Accept: application/json`) returns the same groups, ready for a future live dropdown.

## Tests

`tests/Feature/Search/GlobalSearchTest.php` (5 tests) covers:
- matches across modules, including GSTIN, item code and serial numbers
- short terms, no matches, and literal wildcards
- groups following permissions (inventory and employee roles)
- other companies' data never showing
- the JSON response and the top-bar box

## Manual checklist

1. On a desktop, press `/`, type part of a customer name and press Enter. The ledger, invoices and orders for that customer are listed.
2. Search a serial number. "Serial …" opens the serial report with its history.
3. Log in as a user with the inventory role. Employees and invoices do not appear.
4. At 375 px, tap the search icon, search, and check there is no sideways scroll.

## Done / partial / missing

- Done: global search across masters, documents, batches and serials, with permissions, from the top bar and with keyboard shortcuts.
- Missing:
  - Results that appear as you type, in a dropdown.
  - Searching inside report figures.
  - Configurable shortcuts.
