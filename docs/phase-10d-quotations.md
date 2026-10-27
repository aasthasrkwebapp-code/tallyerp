# Phase 10d: quotations

## What it does

- **Quotations** are under Transactions, numbered QT/0001 onwards.
  - A quotation has the same fields as a sales order: customer, date, a **Valid until** date, an enquiry reference, place of supply, terms, and items with HSN, quantity, rate, discount and GST.
  - It does not post to the accounts or move stock.
  - It has a print page and a PDF.
- **Status**:
  - Open
  - Expired: still open, but the valid-until date has passed
  - Converted
  - Closed: not accepted
  - Cancelled
- **Convert to sales order** copies every line, the terms and the place of supply into a new sales order dated today.
  - The quotation is closed with the reason "Converted to sales order SO/…", and the event is written to the audit trail as `converted`.
  - The sales order shows "Made from quotation QT/…". The quotation links to the sales order.
  - The sales order is then delivered and invoiced as usual (phases 10b and 10c).
- **Alter, close, reopen and cancel** work as for orders.
  - A converted quotation cannot be reopened or converted again.

## Rules enforced

| Rule | Where |
|---|---|
| A quotation cannot be invoiced directly. "Make sales invoice" is hidden, and `?order=` on the invoice form returns to the quotation with an error. | OrderService::invoiceInput |
| An invoice line cannot point at a quotation line. | OrderService::checkInvoiceLines (only non-quotation order kinds) |
| A delivery note cannot be made from a quotation. | DeliveryNoteController (order kind must be sales order) and DeliveryNoteService::orderInput |
| Only open quotations convert. A converted one cannot be reopened. | OrderService::convert, reopen |
| There is no pending report for quotations (404). | OrderController::pending |
| Permissions: a quotation needs `sales.*`. Converting needs `sales.create`. | OrderController |

## Data

- `orders.source_id`: the quotation a sales order was made from. It is a self foreign key and is set to null if the quotation is deleted.
- Migration: `2026_10_28_000400_add_source_to_orders`. It rolls back cleanly.
- Quotations use the `orders` and `order_items` tables with `kind = quotation`.
  - Company deletion and backup already cover these tables. `source_id` is listed in `erp.deletion_tables`, so self references are cleared first.

## Tests

`tests/Feature/Billing/QuotationTest.php` (5 tests) covers:
- numbering, the list, show and PDF pages, and the 404 on the pending report
- conversion: lines, totals, discount, terms, the source link, the audit entry, and refusing a second conversion or a reopen
- refusing invoices and delivery notes made straight from a quotation
- the Expired and Closed labels, and reopening an unconverted quotation
- permissions

## Manual checklist

1. Transactions → Quotations → New. Pick a customer, set Valid until, add items, then Save.
2. Check the status reads **Open**. Print and PDF should show "Valid until".
3. **Convert to sales order**. You land on the new sales order. It shows "Made from quotation".
4. Go back to the quotation. It reads **Converted**, links to the order, and has no Convert or Reopen button.
5. Make a quotation with a past valid-until date. It reads **Expired**.
6. Close a quotation and reopen it.
7. At phone width (375 px), the form, the show page and the list have no sideways scroll.

## Done / partial / missing

- Done: quotations, conversion to sales orders, the expiry label, print and PDF.
- Missing:
  - Converting part of a quotation, or one quotation into several orders.
  - Revision numbers (QT/0001-R1).
  - Emailing a quotation.
