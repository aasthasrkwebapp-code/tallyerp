# 11r. Batches and serial numbers on data import

Batch numbers, expiry dates and serial numbers (10a) could be entered on
screen, but the CSV/Excel import (Data > Import) could not carry them, apart
from a batch number on invoice lines (11h). The import now covers them.

## Stock items

New optional columns:

| Column | Meaning |
|---|---|
| Tracking | `none` (default), `batch` or `serial` |
| Track Expiry | `yes` or `no`; batches only |
| Costing | `average` (default) or `fifo` |
| Opening Batch | batch of the opening stock |
| Opening Mfg Date | YYYY-MM-DD or DD-MM-YYYY |
| Opening Expiry Date | needed when expiry is tracked |
| Opening Serials | one per unit of opening stock, separated by commas, semicolons or new lines |

The rows go through the same checks as the stock item form. A batch item with
opening stock needs a batch, an expiry date when expiry is tracked, and the
expiry must not be before the manufacturing date. A serial item needs exactly
one serial per unit, with no repeats, and a unit without decimals.

## Purchase bills and debit notes

New optional columns: **Mfg Date** and **Expiry Date** (for a new batch), and
**Serials**.

## Sales invoices and credit notes

New optional column: **Serials**.

Invoice lines go through the invoice engine. A sale must name batches and
serials that are in stock in that godown on the date. A purchase cannot bring
in a serial that is already in stock. An expiry date is needed for a new batch
of an item that tracks expiry. Errors name the row.

Dates that cannot be read (for example "next year") are reported, never
guessed.

As before, the import is all or nothing: with any error, nothing is imported,
and the preview lists every row's problems.

## Tests

`tests/Feature/Data/BatchImportTest.php`:

- stock items:
  - a missing expiry, a short serial list and an unknown tracking value are
    each reported on their row
  - a FIFO batch item with dates and a serial item with mixed separators
    import correctly
- a purchase bill:
  - brings in a new batch with its expiry, shown in the batch positions
  - brings in two new serials
- sales:
  - serials and batches in stock go out
  - a serial already sold or not known is refused on its row
- an unreadable expiry date is reported

## Manual checklist

1. Download the stock item template from Data > Import and fill a batch
   item and a serial item with opening stock.
2. Preview, then import. Open each item and check the tracking and opening
   batch or serials.
3. Import a purchase bill with a new batch and expiry, and one with serials.
   Check Inventory > Batches & Expiry and Serial Numbers.
4. Import a sale naming a serial that is not in stock: the preview shows the
   row's error and nothing is imported.

## Done, partial, missing

- **Done:** tracking and opening batches or serials on item import; batch
  dates and serials on invoice import.
- **Missing:** an opening stock split over several batches or godowns in one
  import row (import one batch per item, then add others with a stock
  journal).
