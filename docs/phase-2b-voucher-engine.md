# Phase 2b — Voucher engine

## What was built

* **Voucher types** (`voucher_types`): 8 predefined classes — Journal, Contra, Payment,
  Receipt, Sales, Purchase, Credit Note, Debit Note — plus custom types per class
  (e.g. *Cash Payment*). Automatic or manual numbering, prefix/suffix, zero padding,
  starting number, yearly restart.
* **Posting engine** (`App\Services\Accounting\VoucherService`) — the only code path that
  writes vouchers:
  * total debits must equal total credits (integer paise), at least two lines, amounts > 0
    with at most 2 decimals;
  * header, lines and bill allocations saved in one database transaction;
  * date must be inside an open financial year, not before books beginning, not in a
    locked year/month, not in the future unless allowed in preferences;
  * class rules: contra only cash/bank; payment credits cash/bank; receipt debits cash/bank;
    journal cannot use cash/bank; sales credits a sales ledger; purchase debits a purchase
    ledger; credit note credits and debit note debits a party/cash/bank ledger;
  * numbers assigned only when posted, from a row-locked counter per type and year, and a
    unique index on (company, type, year, number) makes duplicates impossible;
  * states: draft → (pending approval) → posted → cancelled. Drafts may be unbalanced and
    can be deleted. Posted vouchers are never deleted: they are cancelled (number kept,
    excluded from balances) or reversed with an opposite journal;
  * alteration of a posted voucher stores a full snapshot in `voucher_revisions` with
    user, time and reason, and every change is in the audit trail;
  * maker-checker: with *Require voucher approval* on, users without `vouchers.approve`
    submit vouchers as *pending*; approvers post them.
* **Bill-wise details** (`bill_allocations`): New Ref, Agst Ref, Advance, On Account.
  Allocations must add up to the line amount, Agst Ref must match an open bill on the
  opposite side and cannot exceed its pending amount, references are unique per party,
  a party line with no allocations goes on account. Vouchers whose bills are settled by
  other vouchers cannot be cancelled, or altered below the settled amount.
* **Screens**: voucher list with filters, entry form (dynamic lines, live Dr/Cr totals,
  auto-balancing new lines, bill-wise picker with open-bill suggestions, cost centres when
  enabled), voucher view with print layout, cancel / reverse / approve / alteration history.
* Bootstrap and Bootstrap Icons are now served from `public/vendor` (works offline).

## Manual testing checklist

1. **Vouchers → New voucher → Payment**. Choose *Bank Charges* Dr 1,500 and *Cash* Cr 1,500.
   Difference shows 0.00 in green. Post → number `PY/0001`.
2. Enter a journal with Dr 100 / Cr 90 → error shows the difference; nothing is saved.
3. Enter a journal using *Cash* → refused (use payment/receipt/contra).
4. Lock the current month (Financial Years) and try to post into it → refused. Save as draft → allowed.
5. Create a customer ledger under *Sundry Debtors* with bill-wise on. Enter a Sales voucher:
   customer Dr 10,000 with New Ref `INV-1`, Sales Cr 10,000.
6. Enter a Receipt: Bank/Cash Dr 6,000, customer Cr 6,000 → the bill picker suggests `INV-1`
   with 10,000 pending; allocate 6,000 Agst Ref. Try 12,000 → refused.
7. Try to cancel the sales voucher → refused because INV-1 is settled. Cancel the receipt instead.
8. Alter a posted voucher, enter a reason → **Alteration history** lists the previous version.
9. **Cancel / reverse → Post reversal** → a journal with opposite sides is posted and linked.
10. Turn on *Require voucher approval*, sign in as a *User* role member, submit a voucher →
    status *Pending approval*; the owner sees **Approve & post**.
11. On a phone each voucher line becomes a card; there is no horizontal scrolling.
