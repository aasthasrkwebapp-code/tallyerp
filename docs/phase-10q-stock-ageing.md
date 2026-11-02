# Phase 10q: Stock ageing

## What it does

- **Reports → Stock Ageing** (also linked from the stock summary) shows the stock in hand on a date, split by how long ago it was received.
- **How age is worked out**:
  - stock is taken to go out oldest first, so what is in hand is the latest receipts
  - walking back from the newest receipt, each receipt fills the closing quantity until it is used up, and falls in the slab of its receipt date
  - receipts are opening stock (dated on the books beginning date), purchases, sales returns, goods receipt notes and stock-journal inwards
  - company-wide, godown transfers are not receipts, because the stock was already held. For one godown, transfers into it are receipts.
  - if more is in hand than the receipts explain (stock went negative earlier), the extra goes in the oldest slab
- **Slabs**: 0–30, 31–60, 61–90, 91–180 and over 180 days by default. Any 1 to 8 ascending day limits can be entered, such as `15,45,90`. The oldest slab is shown in red.
- **Values**: each slab gets its share of the item's closing value at the item's costing method (average or FIFO). The total matches the stock summary for the same date and godown.
- **Each row shows** the quantity and value in hand, the quantity and value per slab, and the oldest receipt still in hand with its age in days.
- **Filters**: as-on date, godown, stock group (including its sub-groups).
- **Export**: CSV, Excel and PDF, with quantity and value columns per slab and a total row.

## Data

No new tables. The report is worked out from posted invoices, notes and stock journals.

## Permissions

| Action | Permission |
|---|---|
| View the report | `inventory.view` |
| Export it | `reports.export` |

## Tests

`tests/Feature/Inventory/StockAgeingTest.php` (4 tests) covers:
- ageing from the latest receipts after a sale uses up the opening lot, with values that add up to the stock summary
- custom slabs, and the opening stock dated on the books beginning date
- godown transfers ignored company-wide but counted for the receiving godown
- slab parsing and labels
- the page, a slab validation error, the CSV export and permissions

## Manual checklist

1. Give an item opening stock of 10. Buy 5 of it 70 days ago and 4 of it 10 days ago, then sell 12. Open Stock Ageing: 4 are in 0–30 days, 3 in 61–90 days, and the oldest receipt is 70 days old.
2. Change the slabs to `15,45` and press Show. The columns become 0–15, 16–45 and over 45 days.
3. Enter `60,30` as the slabs. An error explains the format.
4. Compare the total value with the stock summary on the same date. They match.
5. Pick a godown that received a transfer. The transferred stock is aged from the transfer date.
6. Export to CSV and Excel.
7. At 375 px the table stacks into cards with no sideways scroll.

## Done / partial / missing

- Done: stock ageing by receipt date with custom slabs, godown and group filters, values that match the stock summary, and export.
- Missing:
  - ageing by batch (the batch and expiry report covers expiry dates)
  - a "slow moving / no movement since" view
