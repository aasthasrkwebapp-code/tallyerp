# 11s. Opening bills on data import

Opening bills (11c) split a bill-wise party's opening balance into the bills
pending when the books began. They had to be typed party by party on the
ledger's Opening Bills screen. Data > Import now takes them from one CSV or
Excel file, for example the outstanding list exported from the old software.

## Columns

| Column | Meaning |
|---|---|
| Party (required) | an existing customer or supplier, by name or alias, kept bill-by-bill |
| Bill Ref (required) | the bill number |
| Date (required) | on or before the date the books begin |
| Due Date | optional, not before the bill date |
| Amount (required) | commas and ₹ are ignored |
| Dr/Cr | optional; defaults to the side of the party's opening balance |

## How rows are applied

- Rows are grouped by party. Each party's rows are **added** to the opening
  bills it already has.
- A row with the reference of an existing opening bill (in any letter case)
  **updates** that bill.
- Bills not in the file are kept.
- Each party goes through the same checks as the Opening Bills screen:
  - dates on or before the books begin
  - amounts above zero
  - no reference entered twice
  - no reference already opened by a voucher
  - a settled bill cannot be cut below what is settled, or switch Dr / Cr
  - the bills may come to no more than the opening balance; the rest stays
    on account
- Errors name the spreadsheet row, for example "Row 3 (A2): an opening bill
  is dated on or before the books begin".
- As with every import, the preview is a full dry run, and the import is all
  or nothing.

Needs `masters.edit`, like the Opening Bills screen.

## Tests

`tests/Feature/Data/OpeningBillImportTest.php`:

1. Errors and saving:
   - a late date and an unknown party are reported with their rows, and
     nothing is saved
   - bills over the opening balance are refused
   - a valid file imports bills for a customer and a supplier, with
     DD-MM-YYYY dates, a due date, commas in amounts, and the default and
     given Dr / Cr
   - a second file updates a bill by reference and adds another, keeping the
     rest
2. The type is listed on the import page, the CSV template has the columns,
   and a viewer cannot download it.

## Manual checklist

1. On Data > Import, open **Opening bills of parties** and download the
   template.
2. Fill bills for two bill-wise parties, with totals within their opening
   balances, and preview.
3. Import, then open each party's Opening Bills screen and the outstanding
   report.
4. Import a file with a bill dated after the books begin: the preview names
   the row.

## Done, partial, missing

- **Done:** import of opening bills, adding and updating per party, with the
  screen's checks.
- **Missing:** setting the party's opening balance from the bill total in the
  same file (import the parties with their opening balances first).
