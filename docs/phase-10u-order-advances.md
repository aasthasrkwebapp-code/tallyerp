# Phase 10u: Advances against orders

## What it does
- An open **sales order** or **purchase order** has an **Advances** card on its page.
- **Record an advance**: enter the date, the amount and the cash or bank ledger.
  - **Sales order**: posts a receipt voucher, Dr cash/bank and Cr customer.
  - **Purchase order**: posts a payment voucher, Dr supplier and Cr cash/bank.
  - On a bill-wise party, the party line opens an **Advance** bill named `ADV-<order number>-<n>`, for example `ADV-SO/0001-1`. It appears in the party's outstanding like any other advance.
  - Advances can't add up to more than the order value. Quotations and closed or cancelled orders take none.
- **Adjust**: once a posted invoice or bill exists against the order, the card offers each open advance against each invoice with an amount due. The amount is pre-filled with the smaller of what is open on the advance and on the invoice.
  - Adjusting posts a **journal on the party ledger**. One line settles the advance bill and the other settles the invoice's bill, both Agst Ref. The party balance doesn't change, but receivables and payables now show the invoice as partly paid and the advance as used.
  - The amount can't exceed what is open on either side.
- **Undo** cancels an adjustment's journal.
- **Cancel** cancels an advance and its voucher. This is refused while any adjustment of the advance stands.
- **Party not kept bill-wise**: the advance is still posted, but no bill is opened. The advance simply reduces the party balance, and the card says there is nothing to adjust.
- The vouchers link back to the order. All actions are written to the audit trail.

## Data
Migration `2026_10_28_001600_create_order_advances_tables` creates:
- `order_advances`: order, date, amount, bill ref, cash/bank ledger, voucher, status
- `order_advance_adjustments`: advance, invoice, date, amount, journal, status

What is open on an advance or invoice always comes from the posted bill allocations, so it can't drift from the books.

## Permissions
| Action | Permission |
|---|---|
| See advances | `sales.view` / `purchases.view` (the order's module) |
| Record or adjust | `sales.create` / `purchases.create` |
| Cancel or undo | `sales.delete` / `purchases.delete` |

## Tests
`tests/Feature/Billing/OrderTest.php` has 2 new tests. They cover:
- the receipt entries and Advance bill
- the over-order-value and wrong-ledger refusals
- adjusting against the invoice, with the advance and invoice pending amounts
- over-adjusting refused
- the cancel order (the adjustment must be undone first)
- a purchase order payment
- quotations refused
- the screens, the voucher links and permissions

## Manual checklist
1. Make a sales order for 2,360. Record an advance of 1,000 into the bank. A receipt voucher is posted, and the customer's outstanding shows advance ADV-SO/…-1 of 1,000.
2. Make an invoice from the order. On the order page, press Adjust (1,000). Receivables now show the invoice with 1,360 due, and no open advance.
3. Press Undo on the adjustment, then Cancel the advance. Both vouchers are cancelled.
4. On a purchase order, record an advance paid. A payment voucher is posted with Dr supplier.
5. At 375 px, the order page has no sideways scroll.

## Done / partial / missing
- Done: advances on sales and purchase orders, adjustment against the order's invoices, undo and cancel, and the bill-wise outstanding kept right.
- Missing:
  - adjusting the advance automatically when the invoice is posted
  - refunding an unused advance from the card (use a payment voucher against the advance bill)
  - GST on advances received for services
