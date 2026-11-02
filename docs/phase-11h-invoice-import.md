# 11h. Sales and purchase invoice import

Data > Import now has two more types:

- **Sales invoices and credit notes** (needs `sales.create`)
- **Purchase bills and debit notes** (needs `purchases.create`)

## Layout

One row per item line. Rows with the same **Invoice** value form one document,
and the head fields (type, date, party, reference, due date, narration) come
from the first row. A later row may leave them blank. If it fills them in,
they must match the first row.

| Column | Notes |
|---|---|
| Invoice | Group key (required) |
| Type | `sales` / `credit_note`, or `purchase` / `debit_note`. Blank = sales or purchase. "credit note", "return" and "bill" are accepted too. |
| Date, Due Date | YYYY-MM-DD, DD-MM-YYYY, DD/MM/YYYY. The due date defaults from the party's credit days. |
| Party | An existing customer / supplier, or a cash / bank ledger, by name (case-insensitive) |
| Reference / Supplier Bill No | Stored as the invoice reference. On purchases it is the supplier's bill number. |
| Item | A stock item (name or code), or a sales / purchase ledger for a service or expense line |
| Ledger | Optional sales / purchase ledger. If blank, the stock item's ledger is used, and if the item has none, the default Sales / Purchase ledger. |
| Description, Quantity, Unit, Rate, Discount %, GST Rate, HSN/SAC, Godown, Batch | The same meaning as on the entry screen. GST rate and HSN default from the stock item. |
| Narration | Optional |

## Rules

- Each document is saved and posted through `InvoiceService`, the same engine
  as the entry screen. That means GST (intra or inter by state), cess, round
  off, stock movement, batch checks, locked periods, financial years,
  numbering and bill-wise references all behave exactly as when entered by
  hand.
- An error names the CSV row. For example, the invoice engine's "Item 2: enter
  a valid rate" becomes "Row 6: enter a valid rate".
- Like every import, the preview is a full dry run, and the file is imported
  only when every invoice is valid.
- **Duplicates:**
  - A purchase bill or debit note with a supplier bill number already entered
    for that supplier (any case) is a duplicate.
  - Otherwise, a document is a duplicate when it has the same kind, party,
    date, reference and grand total as one already entered.
  - The total is known only after calculation, so the invoice is saved inside
    a savepoint and undone when a match is found.
  - Cancelled invoices don't count.
  - With "skip rows that already exist", duplicates are skipped and listed.
    Without it, they are errors.
- The export screen does not list the invoice types; invoices are exported
  from the sales and purchase registers.

## Manual checklist

1. Download the purchase template, fill in two bills (one with two lines), and import. Both are posted, and stock goes up.
2. Import a sales file with a stock line and a service-ledger line. Check that the GST split is CGST/SGST for an in-state customer and IGST for an out-of-state one.
3. Re-import the same purchase file. Each bill is refused with "already entered … with bill number". Tick skip, and the bills are listed as skipped.
4. Put an unknown item on one row. The preview names that row, and nothing is saved.
5. As a viewer, the invoice types are not offered.

## Done / partial / missing

- **Done:** sales, credit note, purchase and debit note import; stock and ledger lines; godown and batch; duplicate detection; per-row errors; templates; tests (`tests/Feature/Data/InvoiceImportTest.php`).
- **Partial:** a batch-tracked item that needs a new batch's expiry or serial numbers can't be imported (there are no columns for them). Enter those by hand.
- **Missing:** exporting invoices in this layout for a round trip; linking an imported credit / debit note to its original invoice; place of supply other than the party's state.
