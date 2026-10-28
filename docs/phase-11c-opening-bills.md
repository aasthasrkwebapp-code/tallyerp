# 11c Opening bill-wise references

A bill-wise party's opening balance can now be split into the bills that were pending when
the books began, as Tally asks for when a ledger is "maintained bill-by-bill".

## What it does

- **Ledger → Opening bills** (`/ledgers/{ledger}/opening-bills`): a table of bill reference,
  bill date, due date, amount and Dr / Cr. Linked from the ledger form (party ledgers) and
  from the customer / supplier page next to *Pending bills*. CSV export.
- Opening bills are treated like bills opened by vouchers (`BillOutstanding`): they show in
  the outstanding summary, ageing buckets, the party statement and the party page (marked
  *Opening*), and in the voucher form's open-bill list, and they are settled with
  **Agst Ref** in receipts, payments, journals and notes. A voucher cannot open a new bill
  with an opening bill's reference.
- The bills may come to the opening balance or less, in the same direction; the rest stays
  *on account*. A bill and an advance (Dr and Cr) can be mixed.

## Checks

- Only party ledgers kept bill-by-bill.
- Reference required, not repeated (case-insensitive), not already opened by a voucher.
- Bill date on or before the books-begin date (later bills are entered as vouchers); due date
  not before the bill date; amount above zero with at most 2 decimals.
- A bill already settled cannot be removed, renamed, flipped Dr / Cr or reduced below what was
  settled.
- On the ledger and customer / supplier forms, the opening balance cannot be cut below the
  opening bills, and bill-by-bill cannot be turned off while opening bills exist.
- Each bill is audited (created / updated / deleted). Bills go with the ledger and are in
  company backups and deletion (`config/erp.php`).

## Permissions

- View: `masters.view`. Save: `masters.edit`. Non-party ledgers return 404.

## Files

- Migration `2026_10_28_002200_create_opening_bills_table.php`, model `OpeningBill`.
- `App\Services\Accounting\OpeningBills` (save, settled, net, ledgerChangeErrors).
- `BillOutstanding` merges opening bills; `Outstanding::bills()` passes `opening`.
- `OpeningBillController`, view `masters/ledgers/opening-bills.blade.php`.
- Tests: `tests/Feature/Accounting/OpeningBillsTest.php` (3).

## Manual checklist

1. Edit a customer with an opening balance of 50,000 Dr, tick bill-by-bill, open *Opening bills*.
2. Enter two bills (30,000 due last quarter, 15,000 not due); save. The message says 5,000 is on account.
3. Outstanding → Receivables: the customer shows 30,000 over 90 days, 15,000 not due, 5,000 on account.
4. Make a receipt Agst Ref one of the bills; the pending amount falls.
5. Try to remove the settled bill or cut its amount below the receipt: refused.
6. Try to lower the ledger's opening balance below the bills: refused.
7. As a viewer, the page opens read-only.

## Done / partial / missing

- Done: entry screen, ageing, settlement, guards, audit, backup, CSV, tests.
- Missing: importing opening bills from a spreadsheet (enter them on the screen for now).
