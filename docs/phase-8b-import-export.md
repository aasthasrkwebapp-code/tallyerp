# Phase 8b — Import and export (CSV and Excel)

Menu **Data → Import** (permission `data.import`, owner and admin) and **Data → Export**
(`data.export`, also accountants). Excel files are read and written with
[PhpSpreadsheet](https://github.com/PHPOffice/PhpSpreadsheet) 5.x (requires PHP 8.2+, extensions
zip, xml, gd, mbstring; installed and tested on PHP 8.2 and 8.3 in CI; the lock is resolved for PHP 8.2).

## Import (`App\Services\Data\ImportService`)

| Import | Needs | Key columns |
|---|---|---|
| Ledgers | masters.create | Name, Group, Opening Balance, Dr/Cr, GST rate, HSN/SAC, bank details |
| Customers / Suppliers | sales.create / purchases.create | Name, GST Type, GSTIN, PAN, State, City, PIN, Address, contact, opening balance, credit days and limit |
| Stock items | inventory.create | Name, Unit, Stock Group, Category, HSN/SAC, GST rate, rates, opening qty / rate / value, godown, reorder level |
| Vouchers | vouchers.post | Voucher (groups rows), Date, Type (journal/payment/receipt/contra), Ledger, Debit, Credit, Narration, Reference |

1. Download the Excel or CSV **template** (headings plus one example row). Headings are matched
   ignoring case and punctuation; extra columns are ignored; up to 5,000 rows per file.
2. Upload: the file is **checked by a full dry run** inside a transaction that is rolled back,
   using the same validation as the entry screens (form requests and the voucher engine):
   unique names, groups, GSTIN/PAN format and state code, PIN, opening balances not allowed on
   income/expense ledgers, unit decimals, balanced vouchers, locked periods and dates.
3. The preview lists every problem by spreadsheet row; **download the error report** as CSV.
4. **Import** is all-or-nothing: if any row fails nothing is saved.

Duplicate detection: names that already exist (case-insensitive) and duplicates inside the file
are errors unless **Skip rows that already exist** is ticked; a GSTIN already used by another
party is an error; a voucher with the same type, date, reference and lines as an existing one is
reported (or skipped). Stock groups and categories are created when missing; units, godowns,
account groups and ledgers must exist. Sales and purchase invoices are not imported as vouchers.

## Export (`App\Services\Data\ExportService`)

Ledgers, customers, suppliers, stock items and posted vouchers (for a period) as Excel or CSV,
with the same headings as the import, so data can be moved to another company or corrected and
re-imported. Exports are audited.

**Every report with a CSV button now also has an Excel button** (`?export=xlsx`): numbers are
stored as numbers with two decimals, everything else as text, so formulas in data are never run.

## Manual test checklist

1. Data → Import → Customers → download the Excel template, fill three rows, one with a 5-digit
   PIN → Check file → the row number and "PIN code must be 6 digits" are shown; nothing saved.
2. Fix the row → Check file → "Every row is valid" → Import → customers appear with GST details.
3. Upload the same file again → "already exists"; tick Skip → all skipped.
4. Vouchers: two journal rows with the same Voucher value and unequal amounts → "must equal".
5. Stock items from .xlsx with a new stock group → group created once; opening value = qty × rate.
6. Data → Export → Customers → Excel; open it in Excel / LibreOffice; import it in another company.
7. Trial balance → Excel; amounts are numeric cells.
8. Accountant: Export works, Import is forbidden.

## Completed / partial / missing

* **Done**: CSV and Excel import of ledgers, customers, suppliers, stock items and vouchers with
  templates, dry-run preview, row-level error report, all-or-nothing import, duplicate detection,
  permissions and audit; export of the same masters and vouchers; Excel on all reports.
* **Partial**: invoices, opening bill-wise references and payroll masters are not importable yet.
* **Missing**: column mapping for files with other headings, background (queued) import for very
  large files, Tally XML import, API integrations and data synchronisation.

Tests: `tests/Feature/Data/ImportExportTest.php` (7 tests).
