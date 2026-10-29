# Phase 5a — Bank reconciliation

## What was built

* **Banking** (`/banking`): choose a bank ledger and an "as on" date.
  * Summary: balance as per books, deposits not yet in the bank, payments not yet in the
    bank, and the balance as per bank (= books − uncleared deposits + uncleared payments).
  * Uncleared entries (or all entries with *Show reconciled too*) with voucher, particulars,
    instrument (voucher reference) and a **bank date** field. The bank date cannot be before
    the voucher date. Every change is written to the audit trail.
  * An entry cleared after the "as on" date counts as uncleared on that date, so past
    reconciliation statements can be reproduced.
* **Statement import** (CSV): columns found by header name — Date; Description / Narration /
  Particulars; Reference / Ref / Cheque / UTR; Withdrawal + Deposit (or Debit + Credit) or one
  signed Amount. Dates as `2026-04-30`, `30-04-2026`, `30/04/2026`, `30-Apr-2026`. The whole
  file is rejected with row numbers if any row is unreadable.
* **Matching**: auto match pairs a statement line with an uncleared posted entry of the same
  amount and direction dated up to 10 days before the statement date; when several qualify
  the reference decides, otherwise the line is left for manual matching from a list of
  candidates. Matching sets the entry's bank date to the statement date; unmatching clears it.
  Unmatched lines (e.g. bank charges) show that a voucher still has to be entered.
* Bank dates survive voucher alteration; cancelling a voucher clears its bank dates and
  statement matches.
* Permissions: `banking.view` to see, `banking.edit` to set dates and match, `banking.create`
  to import, `banking.delete` to delete unmatched statement lines.

## Manual testing checklist

1. Create a bank ledger with an opening balance; post a receipt (cheque no. in Reference) and
   a payment from it.
2. **Banking** → both entries listed as uncleared; balance as per bank = opening balance.
3. Enter a bank date earlier than the voucher date → error. Enter a valid date → the entry
   leaves the list and the bank balance changes.
4. Set *As on* to a date before that bank date → the entry is uncleared again on that date.
5. Import a CSV with the two amounts plus a bank charge → two lines match automatically; the
   bank charge stays unmatched. Post a payment for it, then **Auto match**.
6. Remove a match → the entry is uncleared again.
7. Sign in as a Viewer → the page opens but saving is refused.
8. On a phone the tables become cards with no sideways scrolling.

## Known limitations

* Statement import is CSV only (no OFX/MT940/PDF). Excel files must be saved as CSV.
* No cheque register or cheque printing yet.
