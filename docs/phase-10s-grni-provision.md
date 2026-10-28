# Phase 10s: Goods received not invoiced (GRNI) provision

## Why
A goods receipt note brings stock in, so closing stock goes up. The purchase is only booked when the supplier's bill arrives. At a period end with unbilled GRNs, closing stock includes goods with no purchase booked, so profit is overstated. The provision fixes this for the period.

## What it does
- **Reports → GRNI Provision** shows the GRN lines not yet billed on a date (by default, the end of last month).
  - It uses GRNs dated on or before that date, less bills against them dated on or before it.
  - It shows the journal it would post: each item's purchase ledger debited, **Goods Received Not Invoiced** (a new ledger under Provisions, created when first needed) credited.
  - GRN lines with no rate are listed separately and left out.
- **Post provision** posts that journal on the date.
  - "Reverse it on the next day" is ticked by default. It posts the opposite journal on the next day, so the supplier's bill books the purchase as usual and nothing is counted twice.
- **Only one posted provision per date.** **Cancel** (with a reason) cancels the journal and its reversal, after which the date can be posted again.
- The vouchers link back to the provision and can't be edited directly.
- **Limits**:
  - a GRN cancelled after the date no longer counts for that date
  - the reversal date must be allowed by period locks and the future-date setting, or posting is refused with the reason

## Data
Migration `2026_10_28_001400_create_grni_provisions_table` creates `grni_provisions` with the date, amount, line count, status, journal, reversal and cancellation reason. `DeliveryNoteService::pending()` and `invoicedQuantities()` take an optional as-on date.

## Permissions
| Action | Permission |
|---|---|
| View | `purchases.view` |
| Post a provision | `purchases.create` |
| Cancel a provision | `purchases.delete` |

## Tests
`tests/Feature/Inventory/GrniProvisionTest.php` has 3 tests. They cover:
- the preview on different dates, with part billing, two purchase ledgers and an unvalued line
- the journal and reversal entries, the duplicate-date refusal, cancel and repost, posting without a reversal, and nothing to post
- the screen, the voucher link and permissions

## Manual checklist
1. Post a GRN for 10 units at 120 on 2 May, and bill 4 of them on 5 June.
2. Open GRNI Provision as on 31 May. It shows 1,200 unbilled. Post it with the reversal. JV dated 31 May: Dr Purchase 1,200, Cr Goods Received Not Invoiced 1,200. The reversal is dated 1 June.
3. Check the P&L for May: purchases include the 1,200, matching the stock received.
4. View as on 30 June: 720 unbilled (6 units).
5. Try posting 31 May again: refused. Cancel it with a reason, and both vouchers are cancelled.
6. At 375 px the page has no sideways scroll.

## Done / partial / missing
- Done: a dated GRNI provision with automatic reversal, a preview per purchase ledger, cancel, and audit.
- Missing:
  - an automatic month-end run
  - provision of GST input (GST is booked only with the bill, as the law requires)
  - a GRNI ageing view
