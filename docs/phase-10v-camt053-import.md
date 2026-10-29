# Phase 10v: CAMT.053 bank statement import

## What it does
- **Banking → Import statement** now also reads **ISO 20022 CAMT.053** XML (bank-to-customer statement), which many banks offer next to MT940. Accepted extensions: `.xml`, `.camt` and `.053`. The format is detected from the content.
- **What is read from each booked entry (`Ntry`)**:
  - date: the booking date (`BookgDt/Dt`, or the date part of `DtTm`), falling back to the value date
  - amount and direction: `CRDT` is money in, `DBIT` is money out. A reversal (`RvslInd`) flips the direction.
  - description: the other party's name, the unstructured remittance text and the additional entry information
  - reference: `EndToEndId`, `InstrId` or the cheque number. "NOTPROVIDED" is treated as blank.
  - a stable id: `NtryRef` or `AcctSvcrRef`, so importing the same file again skips the lines already in
- Entries that are not booked (for example `PDNG`, pending) are left out.
- **Balance check**: the opening booked balance (`OPBD`) plus the entries must equal the closing booked balance (`CLBD`). If not, the import is refused with both figures, as for MT940.
- Namespaced and prefixed files of any camt.053 version read the same.
- **Safety**: files with a DOCTYPE or ENTITY declaration are refused, and the XML is read without network access, so the file can't make the server read local files or reach the internet.
- Matching then works as for the other formats.

## Data
No migration. The lines use the `external_id` added in 10o, with ids of the form `camt:<statement id>:<entry ref>`.

## Permissions
As before: `banking.view` to view and `banking.edit` to import.

## Tests
`tests/Feature/Banking/StatementFormatsTest.php` has a new test, `test_camt053_lines_are_read_and_balances_checked`, which covers:
- parsing, including a `DtTm` booking date and an integer amount
- leaving out the pending entry
- the balance mismatch
- a prefixed namespace
- refusing an entity declaration
- import with the skip on re-import

The upload screen test now uploads an `.xml` file too.

## Manual checklist
1. Download a CAMT.053 file from the bank, or use the sample in the test. Import it on the reconciliation page. The lines appear, and those matching vouchers are matched automatically.
2. Import the same file again. Nothing new is added.
3. Edit the closing balance in the file and import it. The import is refused with the difference.

## Done / partial / missing
- Done: CAMT.053 import with the balance check, duplicate protection and safe XML reading.
- Missing:
  - CAMT.052 intraday reports and CAMT.054 notifications
  - currency checks against the bank ledger's currency (amounts are taken as they are)
