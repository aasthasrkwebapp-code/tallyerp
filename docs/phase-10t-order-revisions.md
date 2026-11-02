# Phase 10t: Quotation and order revisions

## What it does
- **Altering** an open quotation, sales order or purchase order **keeps a copy of the earlier version** when anything changes. The document goes up one revision: the first version is "Original" (Rev 0), then Rev 1, Rev 2 and so on.
  - Saving with no changes doesn't make a revision.
- The form has an optional **Reason for this revision**, such as "revised rates after negotiation". It tells you which revision number the save will create.
- **The show page** has a "Rev N" badge and a **Revisions** table: each earlier version with when it was replaced, by whom, the reason, and its total then.
- **Opening an earlier version** shows its party, dates, reference, lines and terms as they were, its total then and the total now. It also lists **what the next version changed**:
  - header fields (date, validity / delivery date, reference, place of supply, narration, terms, party)
  - each line by position (added, removed, or description / quantity / rate / discount / GST changed)
  - the total, with the difference
- **Print and PDF** show the number with its revision, such as "QT/0003 Rev 1", so the customer can see which version they have.
- Orders with invoices or notes against them still can't be altered, as before.
- Each revision is written to the audit trail.

## Data
Migration `2026_10_28_001500_add_order_revisions`:
- adds `orders.revision`
- creates the table `order_revisions` (order, revision, JSON copy of the version, total, reason, who), with one row per order and revision

## Permissions
Revisions follow the order's module: viewing needs `sales.view` or `purchases.view`, and altering needs `.edit`, as before.

## Tests
`tests/Feature/Billing/QuotationTest.php` has a new test, `test_altering_keeps_a_revision`, which covers:
- no revision when nothing changes
- the revision number, display number, saved copy, reason and change list (a changed rate, an added line, the total)
- the show, revision, edit and print pages
- a missing revision returning 404

## Manual checklist
1. Make a quotation. Alter it, change a rate, and enter a reason. The page shows "Rev 1" and the Revisions table lists Original with the reason.
2. Open Original. It shows the old rate, and "What Rev 1 (current) changed" lists the rate change and the new total.
3. Alter it again without changing anything. It stays at Rev 1.
4. Print the quotation. The number reads "QT/… Rev 1".
5. At 375 px, the show and revision pages have no sideways scroll.

## Done / partial / missing
- Done: revisions on quotations and orders with reason, history, a stored copy of each version, a change list, and the revision on print.
- Missing:
  - restoring an earlier version in one click (alter it back by hand)
  - emailing a revision to the party
