# Phase 10n: FIFO costing, bill of materials and manufacturing

## What it does

### Costing method per stock item
- Each stock item has a **costing method**: Weighted average (the default, as before) or **FIFO**. It is set in the item's master.
- **FIFO** values closing stock at the cost of the most recent receipts still in hand. Issues use up the oldest lots first.
  - Opening stock, purchases, goods receipt notes and valued stock-journal inwards each add a lot at their cost.
  - Sales returns, and inwards with no value, come back at the cost of the last issue.
  - Purchase returns use up the oldest lots.
  - Godown transfers don't change cost. A godown's share of the value follows its share of the quantity.
  - Stock sold beyond what is in hand is a shortfall, valued at the latest cost. The next receipt fills it first.
- Closing stock in the stock summary, item page, P&L, balance sheet, ratios and funds flow all use each item's own method.
- Changing the method also revalues earlier closing stock. Nothing is stored per date, so every report is worked out again from the movements.
- The item page shows "FIFO cost" or "Average cost", and the costing method appears under Details.

### Bill of materials
- **Set up**: Stock item → Bill of materials. List the components (any other stock items) and the quantity of each that makes a given quantity of the item, for example "2 Nos of Table take 15.5 Kg of wood and 25 screws".
- **Checks**:
  - each component's quantity must fit that component's unit decimals
  - an item can't be its own component, even through another item's bill of materials
  - a component can't be listed twice
- The item page shows the components and which other items use this item.
- An item that is a component can't be deleted.

### Manufacture
- Stock item → **Manufacture** (shown when a bill of materials exists).
- **Work out components**: shows each component needed for the quantity, how much is in stock (shortages in red) and its cost. Component quantities are rounded up to what the unit allows, e.g. 37.5 screws becomes 38.
- **Manufacture** posts a stock journal of type "Manufacture / consumption":
  - components go out of the chosen godown
  - the finished item comes into its godown, valued at the cost of the components (by each component's costing method) plus any other costs entered, such as labour
  - other costs only add to the stock value; the expense itself is booked with a voucher as usual
- **Limits**:
  - items tracked by batch or serial number must be entered with an ordinary stock journal, since their batches have to be chosen
  - a negative-stock warning appears after saving, as for other stock journals

## Data

Migration `2026_10_28_001100_add_fifo_costing_and_bom`:
- `stock_items.costing_method` (average / fifo) and `stock_items.bom_quantity`
- table `bom_components` (item, component, quantity)

## Permissions

| Action | Permission |
|---|---|
| View the bill of materials on the item page | `inventory.view` |
| Set up, change or remove a bill of materials, change the costing method | `inventory.edit` |
| Manufacture | `inventory.create` |

## Tests

`tests/Feature/Inventory/FifoBomTest.php` (5 tests) covers:
- a FIFO worked example through purchases, sales, sales and purchase returns, a shortfall and a refill, with the P&L closing stock
- the cost of an issue
- switching back to average
- godown transfers under FIFO
- a bill of materials with mixed costing, rounding and other costs, checked line by line
- validation, including cycles and tracked items
- the screens and permissions

## Manual checklist

1. Make an item with FIFO costing and opening stock of 10 @ 100. Buy 10 @ 120 and sell 15. The item page shows 5 in stock with a value of 600; average would have shown 550.
2. Set up a bill of materials for a finished item with two components, then open Manufacture. Enter a quantity and press **Work out components**. The quantities, stock and costs show.
3. Manufacture it with other costs of 300. The stock journal takes the components out, and brings the item in at (component cost + 300) ÷ quantity.
4. Try to delete a component: this is refused.
5. At 375 px, the item page, bill of materials and manufacture screens have no sideways scroll.

## Done / partial / missing

- Done: FIFO or weighted average per item, the bill of materials, and manufacturing that posts a costed stock journal.
- Missing:
  - LIFO and standard cost
  - by-products and scrap in the bill of materials
  - several bills of materials per item
  - manufacturing batch- or serial-tracked items from the bill of materials
  - a job work / sub-contracting flow
