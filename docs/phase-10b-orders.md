# Phase 10b: sales and purchase orders

## What it does

- **Sales orders** (from customers) and **purchase orders** (to suppliers) are under Transactions.
  - Each kind is numbered on its own: SO/0001 and PO/0001.
  - Each has an order date, delivery date, the customer's PO or supplier quotation reference, place of supply, and items with stock item, HSN, quantity, rate, discount and GST.
  - GST (CGST+SGST or IGST) is worked out the same way as on invoices.
  - Orders do not post to the accounts or move stock.
- **Make an invoice from an order.**
  - **Make sales invoice** (or **Make purchase bill**) opens the invoice form with the order's pending lines. The sales ledger comes from the stock item.
  - You can lower the quantities to deliver in parts.
  - Each invoice line keeps a link to its order line. The invoice page shows "Order SO/0001" on those lines.
- **Delivery status**: Pending, Part delivered, Delivered, Closed or Cancelled.
  - It is worked out from posted invoices.
  - Drafts do not count. A cancelled invoice puts its quantity back on the order.
- **Alter** an order only while no invoice (not even a draft) refers to it.
- **Cancel** an order only while nothing has been invoiced against it.
- **Close** (short-close) an order to stop invoicing its pending quantity, with a reason. You can **reopen** it later. Closing, cancelling and reopening are written to the audit trail.
- **Pending Sales Orders / Pending Purchase Orders** reports (Reports section):
  - list the open order lines with ordered, invoiced and pending quantity and pending value (before tax)
  - filter by party or by overdue delivery date
  - export to CSV, Excel and PDF
- Each order has a **print** page and a **PDF**.
- Permissions follow the module: sales orders need `sales.*` and purchase orders need `purchases.*`.

## Rules enforced on the invoice

| Rule | Error |
|---|---|
| The order line belongs to an order of the same kind and the same party | "the order line does not belong to an order of this party" |
| The order is open (a closed order still accepts lines already on this invoice) | "order SO/0001 is closed" |
| The invoice line has the same stock item as the order line | "the item differs from line N" |
| Quantity is no more than pending (other posted invoices and earlier lines of this invoice count; the invoice being edited does not) | "only N is pending on order …" |
| Credit and debit notes cannot be linked to orders | "only sales invoices and purchase bills …" |

## Tests

`tests/Feature/Billing/OrderTest.php` has 8 tests:
- numbering and tax
- validation
- part delivery, drafts, cancelling and editing invoices
- order-line matching
- closing, cancelling and altering
- screens and the pending report (CSV, PDF)
- permissions

## Manual checklist

1. Transactions, then Sales Orders, then New: pick a customer and two items, then save. The order shows Pending and its GST.
2. Make sales invoice: the lines are filled with an "Order SO/…" badge. Change one quantity to part of the order and post. The order shows Part delivered with the pending quantity.
3. Make another invoice: only the pending quantity is offered. Enter more than pending: refused.
4. Try Alter on the order: hidden (it has an invoice). Close the order with a reason. Pending Sales Orders no longer lists it. Reopen it.
5. Cancel the first invoice: the quantity is pending again on the order.
6. Purchase Orders: the same steps with a supplier and a purchase bill.
7. Print and PDF of an order.
8. Check every screen at phone width.

## Status

| Item | Status |
|---|---|
| Sales and purchase orders with GST, print, PDF | Done |
| Invoices / bills against orders with part delivery and pending-quantity check | Done |
| Close / reopen / cancel with audit | Done |
| Pending orders reports with export | Done |
| Quotations (before the sales order) | Missing |
| Delivery notes and goods receipt notes (stock moved before the invoice) | Missing: next phase |
| Advance receipts and payments against an order | Missing |
| Order import / export (CSV/Excel) | Missing |
