# Phase 2a — Chart of accounts (groups, ledgers, cost centres)

## What was built

* **Default structure.** Every new company receives 28 predefined groups (15 primary,
  13 sub-groups) with nature (asset / liability / income / expense) and a gross-profit
  flag, plus default ledgers: Cash, Sales, Purchase, Input/Output CGST/SGST/IGST,
  Round Off, Discount Allowed/Received, Bank Charges, Interest Received, Capital, and a
  *Primary Cost Category*. Code: `App\Services\Setup\DefaultChartOfAccounts`.
* **Groups.** Tree view, create/edit/delete. Sub-groups inherit nature; moving a group
  updates its whole branch; a group cannot be placed under itself. Predefined groups can
  be renamed but not moved or deleted.
* **Ledgers.** Search and filter by group/type, opening balance with Dr/Cr, warning when
  opening balances do not net to zero. The ledger type (cash, bank, party, tax, sales,
  purchase, stock, general) is derived from the group on the server. Bank, credit
  control, tax and GST sections appear only when relevant. Income/expense ledgers cannot
  carry opening balances. Predefined ledgers cannot be deleted; a ledger with postings
  cannot be deleted (it can be deactivated).
* **Cost centres** with categories; activate/deactivate; delete blocked when used.
* **Money** is handled in integer paise (`App\Support\Money`) with Indian digit grouping.
* All masters are company-scoped and audited (old/new values).

## Manual testing checklist

1. Create a company → **Account Groups** lists 28 groups in a tree.
2. Create group *Vehicle Expenses* under *Indirect Expenses* → nature shows *Expenses*.
3. Try to delete *Sundry Debtors* → refused.
4. **Ledgers → New ledger**: choose *Bank Accounts* → bank section appears; enter IFSC `hdfc0001234` → saved as uppercase.
5. Create a supplier ledger under *Sundry Creditors* with opening 1,200.50 Cr → the list shows a warning about the opening balance difference.
6. Create *Rent* under *Indirect Expenses* with an opening balance → refused.
7. Rename *Cash* to *Cash in Hand* → **Audit Trail** shows the change.
8. **Cost Centres**: add a cost centre; adding the same name again is refused.
9. On a phone-width screen the ledger table turns into cards.
