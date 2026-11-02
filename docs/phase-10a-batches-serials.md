# Phase 10a: batch and serial numbers

## What it does

- **Choose tracking per item.** A stock item is tracked by *none*, *batch / lot numbers* or *serial numbers (one per unit)*.
  - A batch item can also require expiry dates.
  - Tracking is fixed once any document moves the item. Opening batches and serials then stay as they were.
- **Opening stock.**
  - Batch items take an opening batch number, plus a manufacturing and expiry date.
  - Serial items take one serial number per opening unit.
- **Invoices** (sales, purchases, credit and debit notes):
  - Lines of a tracked item ask for a batch number or for serial numbers.
  - Purchases and sales returns can bring in a new batch with its dates.
  - Sales and purchase returns offer the batches in stock, with quantity, expiry and godown.
  - Invoice show, print and PDF pages list the batch, expiry and serials.
- **Stock journals.**
  - Adjustments take a batch or serials per line.
  - Transfers move the batch (with its expiry) or the serials to the other godown.
- **Reports** (menu, Reports section):
  - **Batches & Expiry** lists batch stock as on a date per godown. It shows days to expiry and can filter to expiring-soon or expired batches.
  - **Serial Numbers** shows where each serial is and lets you search one serial for its full history.
  - Both export to CSV, Excel and PDF.

## Rules enforced (server side)

| Rule | Where |
|---|---|
| A batch item needs a batch number on every line; an item with expiry needs an expiry date when the batch is new | inward lines |
| A batch already on record keeps its dates; a different expiry is refused | inward lines |
| Expiry must not be before the manufacturing date | inward lines and opening |
| An outgoing batch must be in that godown with enough quantity, both on the document date and today | outward lines |
| Sales may not use an expired batch (purchase returns and stock write-offs may) | sales |
| Serial items use whole quantities, with exactly one serial per unit and no repeats | all lines |
| A serial coming in must not already be in stock; a serial going out must be in stock in that godown | all lines |
| A serial cannot move on a date before its last movement | all lines |
| Editing a posted document leaves its own saved lines out of the balances | edits |
| Cancelled and draft documents do not count | balances |

Batch numbers and serials are compared without regard to case and stored in upper case.

## Design

Balances are not stored. `App\Services\Inventory\BatchTracker` works them out from the opening stock and the `batch_no`, `mfg_date`, `expiry_date` and `serials` columns of posted invoice items and stock journal lines. It reads these through `StockLedger::movements()`, so batch stock always agrees with the documents.

On the same day, movements are ordered as receipts, then transfers (out before in), then issues.

Valuation stays weighted average for the whole item. Batch-wise or FIFO costing is not done.

## Tests

`tests/Feature/Inventory/BatchSerialTest.php` has 7 tests:
- batch purchase and sale rules
- expiry blocking
- transfers
- serial rules and history
- cancel restoring a serial
- the item form
- the pages

## Manual checklist

1. Create an item with *Batch / lot numbers* and *expiry*, with opening batch LOT1 and an expiry date. Save without an expiry: an error is shown.
2. Purchase bill: choose the item. Batch, Mfg and Expiry fields appear. Enter a new batch and post.
3. Sales invoice: choose the item. The batch field lists batches in stock. Sell more than the batch holds: an error is shown.
4. Set a batch's expiry in the past and try to sell it: refused. Write it off with a stock journal (Out): allowed.
5. Create an item tracked by *Serial numbers* with 2 opening units and serials A1, A2. Sell A1, then try to sell A1 again: refused.
6. Credit note for A1: it is back in stock. Open Reports, then Serial Numbers, search A1 and check its history.
7. Transfer a batch to a second godown. Batches & Expiry shows it there with the same expiry.
8. Try to change tracking on an item that has documents: refused.
9. Check every screen above at phone width.

## Status

| Item | Status |
|---|---|
| Batch numbers with mfg / expiry dates, expiry alerts | Done |
| Serial numbers per unit with history | Done |
| Batch / serial on invoices, returns, stock journals, opening stock | Done |
| Batch / serial on import (CSV/Excel) | Missing: imported vouchers and stock items do not carry batches or serials |
| Batch-wise or FIFO valuation | Missing |
| Barcode printing for serials | Missing |
