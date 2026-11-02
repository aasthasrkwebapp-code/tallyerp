# Phase 6a — Inventory

Menus: **Masters → Stock Items** and **Units, Groups & Godowns**; **Transactions → Stock
Journals**; **Reports → Stock Summary** and **Reorder Status**. Permissions: `inventory.view`,
`inventory.create`, `inventory.edit`, `inventory.delete` (the Inventory role has all of them;
Viewer, Sales and Purchases roles can view).

## How stock is recorded

There is no separate stock table to fall out of step. Stock moves come from:

* **Posted invoices** whose items name a stock item: purchase bills bring stock in, sales take it
  out, credit notes (sales returns) bring it back, debit notes (purchase returns) take it out.
  Drafts, pending and cancelled documents do not move stock.
* **Posted stock journals**: godown transfers and adjustments (shortage, damage, excess,
  consumption, production). Cancelling a journal removes its effect.
* **Opening stock** entered on each item (quantity, value and godown as at the books beginning).

Code: `App\Services\Inventory\StockLedger` (movements, positions, item ledger, godown quantities)
and `App\Services\Inventory\StockJournalService`.

## Valuation

Weighted average cost up to the valuation date, company-wide:
(opening value + purchases − purchase returns + valued stock-journal inwards) ÷ the same
quantities, applied to the closing quantity. Sales returns and transfers move quantity only.
Stock-journal inward lines take the entered rate, or the current average cost if left blank.

## Effect on the statements

* **Trial balance**: an *Opening stock* row (stock at the start of the year). Opening stock at the
  books beginning counts as a debit when checking that opening balances agree.
* **Profit & loss**: opening stock is added to direct costs and closing stock to trading income,
  so gross profit reflects cost of goods sold.
* **Balance sheet**: *Closing stock* shown under assets; the stock change of earlier years is part
  of the brought-forward Profit & Loss A/c.
* No journal entry is needed for closing stock; do not also post one to a stock ledger.

## Masters

* **Units** with decimal places (quantities are checked against them) and GST UQC codes. Nos,
  Pcs, Box, Kg, Gm, Ltr, Mtr and Hrs are created with each company.
* **Stock groups** (nested) and **stock categories**.
* **Godowns** (locations). One is the default (Main Location) and is used whenever none is chosen.
* **Stock items**: code/SKU, group, category, unit, HSN/SAC, GST rate, selling and purchase rates
  and ledgers, opening stock (value or quantity × rate), reorder level, active flag.
* Records in use cannot be deleted (items with movements, units/groups/categories with items,
  godowns stock has moved through, the default godown). The unit of an item cannot change once
  it has been used.

## Invoices

Each invoice line can name a stock item (and a godown when there is more than one). Choosing the
item fills the ledger, description, unit, HSN, GST rate and rate; on saving, blanks are filled
from the item the same way. Selling or returning more than is held is allowed but shows a
negative-stock warning.

## Reports

* **Stock summary** as on any date, by godown, grouped by stock group with group totals; CSV.
* **Stock item** page: quantity, average cost and value, quantity by godown, and a movement ledger
  for a period (with godown filter) linking to each invoice or journal.
* **Reorder status**: active items at or below their reorder level, or negative.

## Manual test checklist

1. Units, Groups & Godowns: add a unit `Doz` with UQC `doz` (saved as DOZ); add a group under
   another group; add a godown "Shop"; try deleting the unit Nos while an item uses it (refused).
2. New stock item with opening 20 @ 1,750 → value fills as 35,000.00; ledger shows opening
   balance difference until capital or another ledger matches it.
3. Purchase bill choosing the item: ledger, unit, HSN, GST and rate fill in; post it.
4. Stock summary shows the new quantity and average cost; the P&L shows opening and closing
   stock; the balance sheet still agrees.
5. Sales invoice for more than in stock → posted with a negative-stock warning.
6. Stock journal transfer Main Location → Shop; item page shows the split by godown.
7. Stock adjustment out (damage); cancel it with a reason; quantities return.
8. Set a reorder level above the stock → item listed in Reorder Status and highlighted in the list.
9. On a phone-width screen all inventory pages fit without sideways scrolling.

## Completed / partial / missing

* **Done**: units, groups, categories, godowns, stock items, opening stock, stock on invoices
  and returns, godown transfers, adjustments, cancelling journals, weighted average valuation,
  stock in trial balance / P&L / balance sheet across years, stock summary, item ledger,
  reorder status, negative-stock warning, permissions, audit trail on masters and journals.
* **Partial**: production is recorded as a stock journal (consumption out, finished goods in at a
  rate); there is no bill of materials. Stock journals cannot be edited, only cancelled and
  re-entered.
* **Missing**: batch and serial numbers, expiry, FIFO valuation, purchase and sales orders,
  delivery notes and GRN, goods in transit, stock ageing, alternate units, price lists,
  physical stock verification screen.

Tests: `tests/Feature/Inventory/InventoryTest.php` (13 tests).
