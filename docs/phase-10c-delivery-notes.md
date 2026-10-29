# Phase 10c: delivery notes and goods receipt notes

## What it does

- **Delivery notes (DN/0001…)** record goods sent to a customer before the sales invoice.
- **Goods receipt notes (GRN/0001…)** record goods received from a supplier before the purchase bill.
- Both are under Transactions.
- **A note moves stock when it is saved.**
  - A delivery note takes stock out of the godown.
  - A GRN brings it in at the rate entered: the order rate or the item's purchase rate. That value counts in the weighted average cost.
  - Notes do not post to the accounts.
- **Make sales invoice / Make purchase bill** from a note fills the invoice with the lines not yet invoiced.
  - The invoice line keeps a link to the note line and **does not move stock again**. It takes its godown, batch and serials from the note.
  - The rate, discount and GST come from the order line when the note was made from an order.
- **From an order**: **Make delivery note** (or **Make goods receipt note**) on an open order fills the note with the order's pending stock lines.
  - Goods on a posted note count as delivered against the order.
  - The invoice made from the note does not count against the order a second time.
- **Batches and serials** are entered on the note and checked like any other stock movement.
- **Cancel** a note to reverse its stock movement. This is allowed only while no posted invoice uses it.
  - A GRN cannot be cancelled once its batches or serials have gone out.
  - Cancelling is written to the audit trail.
- **Delivered, Not Invoiced** and **Received, Not Billed** reports (Reports section):
  - list note lines without a posted invoice, with their value at the note rate
  - filter by party
  - export to CSV, Excel and PDF
- Each note has a **print** page and a **PDF** (delivery challan / GRN with signature blocks).
- The item stock ledger and the serial report link to the notes.
- Permissions: delivery notes need `sales.*` and GRNs need `purchases.*`.

## Rules enforced

| Rule | Where |
|---|---|
| Note dates must be in an open financial year and not in a locked period | note |
| Order lines on a note must belong to an open order of the same party and item, and not exceed the pending quantity | note |
| An invoice line against a note line needs the same party, the same item, a posted note, and no more than the quantity still to invoice | invoice |
| Only sales invoices (for delivery notes) and purchase bills (for GRNs) can use notes; credit and debit notes cannot | invoice |
| An order with a note against it can no longer be altered | order |

## Design notes

- `invoice_items.note_item_id` marks invoice lines whose stock was moved by a note. `StockLedger::movements()` skips them and adds the note lines (kinds `delivery` and `receipt`).
- `OrderService::delivered()` counts posted invoice lines linked straight to an order plus posted note lines linked to it. Invoice lines made from a note carry no order link.
- A purchase bill against a GRN does not change the stock value. If the bill rate differs from the GRN rate, the stock value stays at the GRN rate.

## Tests

`tests/Feature/Inventory/DeliveryNoteTest.php` has 6 tests:
- stock out, part invoicing and cancelling
- GRN valuation
- orders → notes → invoices without double counting
- serials on notes and GRN cancel protection
- invoice line matching
- screens, reports and permissions

## Manual checklist

1. Open a sales order and choose **Make delivery note**. The lines show the order badge. Save. The stock summary drops by the quantity, and the order shows Part delivered or Delivered.
2. On the note, choose **Make sales invoice**. Lines show "DN/… · line 1 — stock already moved by the note". Post it. Stock does not change again.
3. Try to invoice more than the note: refused. Delivered, Not Invoiced lists only the rest.
4. Try to cancel the note: refused while the invoice is posted. Cancel the invoice, then cancel the note: stock comes back.
5. Purchase order, then **Make goods receipt note**, then **Make purchase bill**: the same flow. The GRN rate sets the stock value.
6. A serial-tracked item: receive serials on a GRN, deliver one on a DN, then try to cancel the GRN: refused.
7. Print and PDF of a note. Check every screen at phone width.

## Status

| Item | Status |
|---|---|
| Delivery notes and GRNs moving stock, with batches and serials | Done |
| Notes from orders; invoices / bills from notes without moving stock twice | Done |
| Not-invoiced / not-billed reports with export | Done |
| Cancel with stock reversal and safeguards | Done |
| Rejection-in / rejection-out notes (returns against a note) | Missing; use credit / debit notes |
| One invoice combining several notes in one step | Partial: one note at a time, though an invoice can hold lines from several notes |
| Goods-received-not-invoiced provision in the accounts | Missing |
