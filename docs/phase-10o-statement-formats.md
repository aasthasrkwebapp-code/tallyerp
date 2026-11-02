# Phase 10o: OFX and MT940 bank statement import

## What it does

Bank reconciliation → **Import statement** now accepts three formats:

- **CSV**, as before. Columns are found by their header names.
- **OFX / QFX**, the files that banks and accounting software download. Both versions work: version 1 (SGML, with unclosed tags) and version 2 (XML).
  - Each STMTTRN becomes a line, using:
    - the posting date
    - the signed amount: negative is a withdrawal
    - NAME and MEMO as the description
    - CHECKNUM or REFNUM as the reference
  - Zero-amount transactions are skipped.
- **SWIFT MT940**, the standard statement format for corporate banking.
  - Each :61: line becomes a line, with its date, debit or credit mark and amount. A reversal of a debit (RD) is money in; a reversal of a credit (RC) is money out.
  - The reference comes from the :61: line (NONREF is left blank). The description comes from the :86: line, including structured ?20–?29 subfields.
  - **Balance check**: each statement (or page, :60M: to :62M:) is checked. The opening balance plus the transactions must equal the closing balance. If it doesn't, the import is refused, because the file may be incomplete.

The format is detected from the file's content, so the extension doesn't matter as long as it is one of .csv, .txt, .ofx, .qfx, .sta, .mt940 or .940.

**Importing the same file again is safe.** OFX lines keep the bank's FITID, and MT940 lines keep the bank reference (or their date and position in the statement). Lines already imported for that bank ledger are skipped, and the message says how many. CSV files have no such id, so re-importing a CSV still doubles its lines, as before.

After import, auto-matching runs as before.

## Data

Migration `2026_10_28_001200_add_external_id_to_bank_statement_lines` adds `bank_statement_lines.external_id`, with an index.

## Permissions

Unchanged: `banking.create` to import, `banking.view` to see.

## Tests

`tests/Feature/Banking/StatementFormatsTest.php` (4 tests) covers:
- OFX 1 and OFX 2 reading, including entities and dates with a time zone
- MT940 reading with structured :86:, NONREF and the balance check
- importing, auto-matching and skipping duplicates
- the upload screen, including a refused PDF

## Manual checklist

1. Download an OFX file from the bank and import it on the reconciliation screen. The lines appear and matching ones are matched.
2. Import the same file again. The message says all the lines were skipped as already imported.
3. Import an MT940 (.sta) file. If you edit its closing balance, the import is refused with the balance message.

## Done / partial / missing

- Done: OFX / QFX and MT940 import with duplicate protection and the MT940 balance check.
- Missing:
  - CAMT.053 (ISO 20022 XML)
  - bank-specific CSV layouts saved as templates
  - duplicate protection for CSV
