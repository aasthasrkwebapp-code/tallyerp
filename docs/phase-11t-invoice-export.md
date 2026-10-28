# 11t. Invoices and opening bills export in the import layout

Data > Export wrote ledgers, parties, stock items and vouchers with the same
columns as the import, so a file could be corrected and imported into another
company. Invoices (11h) and opening bills (11s) could be imported but not
exported. Now they can be.

## New exports

- **Sales invoices and credit notes**, and **Purchase bills and debit notes**:
  - posted documents in a date range (cancelled ones are left out)
  - one row per line, keyed by the document number in the Invoice column
  - the header fields (type, date, party, reference or supplier bill number,
    due date, narration) are on the first row of each document, as the import
    reads them
  - a stock line names the item by code (or name). The Ledger column is
    filled only when the line uses a ledger other than the item's own.
  - a service or expense line names its ledger in the Item column
  - quantity, unit, rate, discount, GST rate, HSN, godown and batch; batch
    dates on purchases; serials as a comma-separated list
- **Opening bills of parties**: party, bill reference, date, due date, amount
  and Dr / Cr.

The stock item export now also fills the new columns from 11r: tracking,
expiry tracking, costing, and the opening batch with its dates or the opening
serials.

Each export needs `data.export` and the module's view permission
(`sales.view`, `purchases.view` or `masters.view`). It is in the audit log.
CSV and Excel are offered, as for the other exports.

## Round trip

Export from one company and import into another that has the same parties,
items and ledgers. The documents post with the same totals. Purchases go
first, so items and serials are in stock before the sales.

The new company numbers the invoices in its own series. The old number is not
kept, except as the grouping key in the file.

## Tests

`tests/Feature/Data/InvoiceExportTest.php`:

- purchase bills (a stock line, an expense line, a serial line), a sale with
  a discount and a serial, an opening bill and a cancelled sale are entered
- the exports have the import headers and the expected rows, and leave out
  the cancelled sale
- the date range filters the rows
- the three files import into a second company with no errors and the same
  invoice totals
- the export page lists the new types and the Excel download works
- a viewer without `data.export` is refused

## Manual checklist

1. Data > Export: download **Purchase bills and debit notes** and
   **Sales invoices and credit notes** for a month, as Excel.
2. Check that each line has the item, quantity, rate and GST, and that the
   document fields are on its first row.
3. In another company with the same masters, import the purchase file, then
   the sales file. Compare the sales register totals.
4. Download **Opening bills of parties** and import it into the other company.

## Done, partial, missing

- **Done:** invoice and opening bill exports in the import layout, the
  stock item export with tracking, and a tested round trip.
- **Missing:** cess and reverse charge columns (neither the import nor the
  export carries them yet); Tally XML.
