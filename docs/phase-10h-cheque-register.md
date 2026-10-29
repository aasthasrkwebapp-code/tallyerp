# Phase 10h: cheque register, cheque books and cheque printing

## What it does

- **Cheque / bank transfer details** on every voucher form. They are open by default on payment, receipt and contra vouchers.
  - Instrument: cheque, demand draft, NEFT, RTGS, IMPS or UPI.
  - The number, which is the cheque number or the UTR.
  - The instrument date. If left blank, the voucher date is used for cheques and drafts. A later date makes it post-dated.
  - The name on the cheque. If left blank on an issued cheque, the party ledger's name is used.
- **Issued or received** is worked out from the bank line:
  - Bank credited means issued, and that bank is the paying bank.
  - Bank debited means received.
  - A voucher can have only one paying bank. An instrument without a bank line is refused.
- **Cheque books** (Banking → Cheque Books): the leaf range for each bank account.
  - Once a bank has a book, every cheque it issues must come from one of its books. Each leaf can be used only once while its voucher is not cancelled.
  - Cancelling a voucher frees its leaf.
  - Overlapping books are refused. A book with used leaves cannot be deleted, but it can be made inactive.
  - The page shows how many leaves are used, spoilt and unused, the next unused leaf, and any leaves skipped before the last one used.
  - **Spoilt leaves** (torn, written wrongly, lost) are recorded with a date and a reason so they are never used. This can be undone.
- **Next leaf suggestion**: on the voucher form, choosing Cheque fills in the paying bank's next unused leaf.
- **Cheque Register** (Banking → Cheque Register):

  | Status | Meaning |
  |---|---|
  | Cleared | The bank line has a bank date, set in bank reconciliation. The date is shown. |
  | Not yet cleared | Posted, not cleared. Shows the number of days since the cheque date. |
  | Stale | A cheque or draft not cleared within 90 days. RBI rules make cheques valid for three months. |
  | Post-dated | The instrument date is after today. |
  | Cancelled / Reversed | The voucher was cancelled, or reversed (for example, a dishonoured cheque). A reversal does not copy the cheque. |
  | Draft | The voucher is a draft or waiting for approval. |
  | Spoilt leaf | A leaf from the cheque books, with its reason. |

  - Filters: bank, issued or received, status, instrument, date range, and number or name.
  - The count and amount for each status are shown, and each one is a link that filters the list.
  - Export to CSV, Excel or PDF.
- **Print cheque** (voucher page, issued cheques) prints the date, payee, amount in words and amount in figures, with an optional "A/c Payee" crossing, on the cheque leaf.
  - Positions are set in millimetres for each cheque book, because each bank's leaf differs slightly. The defaults suit a CTS-2010 leaf (202 × 92 mm).
  - Each print is written to the audit trail as `cheque_printed`.

## Data

- `vouchers` gains `instrument_type`, `instrument_number`, `instrument_date`, `payee_name`, `instrument_account_id` and `instrument_direction`. Cheque numbers are stored padded to 6 digits.
- New tables: `cheque_books` (with a JSON `layout`) and `spoilt_cheques`.
- A bank ledger with cheque books cannot be deleted.
- Backups and company deletion include the new tables.
- Migration: `2026_10_28_000700_create_cheque_register_tables`. It rolls back cleanly.

## Permissions

| Action | Permission |
|---|---|
| View the register and books, print cheques | `banking.view` |
| Add a cheque book | `banking.create` |
| Alter a book or its layout, mark or unmark spoilt leaves | `banking.edit` |
| Delete a book | `banking.delete` |
| Enter the instrument on a voucher | the voucher's own permissions |

## Tests

`tests/Feature/Banking/ChequeRegisterTest.php` (6 tests) covers:
- defaults, the print page and the amount in words
- book ranges, reuse, spoilt leaves, overlaps, the next leaf, and cancelling a voucher to free its leaf
- every register status, received cheques and filters
- reversal
- validation, and NEFT with a UTR
- the print layout and permissions

## Manual checklist

1. Banking → Cheque Books → add 500101 to 500150 for your bank.
2. Make a new payment voucher, open the cheque details and choose Cheque. 500101 is filled in. Post it.
3. On the voucher, press Print cheque. Print on plain paper, hold it over a leaf, and adjust the layout on the book if needed.
4. Try to use 500101 again. It is refused.
5. Mark 500103 spoilt. 500102 is suggested next, and 500103 is never accepted.
6. In Bank Reconciliation, set the bank date. The register shows the cheque as Cleared.
7. At 375 px, the register, the books page and the voucher form have no sideways scroll.

## Done / partial / missing

- Done: cheque and transfer details on vouchers, cheque books, spoilt leaves, the register with cleared, stale and post-dated status, exports, and cheque printing with a layout for each book.
- Missing:
  - Batch cheque printing (several cheques in one print run).
  - Positive Pay file upload to the bank.
  - Showing the instrument number on the bank reconciliation screen.
  - Instrument details on sales invoice receipts. Today they are entered on receipt vouchers.
