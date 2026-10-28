# Phase 10y: Goods in transit

## What it does
- A **godown transfer** can be saved as **goods in transit**, using the tick box on the stock journal form. This is the **dispatch**:
  - The stock leaves the source godown at once.
  - It goes into the company's **Goods in Transit** godown, which is made automatically the first time it is needed.
  - The destination godown is recorded on the journal.
- While in transit, the goods stay in closing stock at cost, but are in neither godown. Stock summary by godown shows them under Goods in Transit.
- The dispatch page shows each line as dispatched, received, short and in transit. A **Receive** form takes a date, a note, and for each line a quantity **received now** and a quantity **short now**. It posts:
  - a **transfer** from the transit godown to the destination for the received quantity
  - a **stock adjustment** that writes off the shortage from the transit godown at average cost
  - Both are linked to the dispatch and show a banner pointing back to it.
- Goods can be received in parts, on different dates.
- Batches keep their number (and expiry) through transit.
- Items with serial numbers are received in full; adjust any missing serial at the destination afterwards.
- These are refused:
  - receiving or writing off more than is still in transit
  - a date before the dispatch
- A dispatch can't be cancelled while a receipt or shortage against it stands. Cancelling a receipt puts its goods back in transit.
- **Goods in Transit report** (Reports menu) lists what is still in transit on a date: dispatch, from → to, days in transit, item, quantity and value at the item's cost on that date, with a total. It has CSV, Excel and PDF export.
- The transit godown is kept out of the godown choices on invoices, notes, stock journals, manufacture and opening stock. It is never the default godown, and ordinary journals can't move stock in or out of it.
- The stock journal list marks dispatches "In transit" and their receipts "Transit receipt".

## Data
Migration `2026_10_28_001900_add_goods_in_transit` adds:
- `godowns.is_transit`
- `stock_journals.transit_to_godown_id` (the destination of a dispatch)
- `stock_journals.dispatch_id` (on receipts and shortages)
- `stock_journal_lines.transit_line_id`: the dispatch line that a receipt or shortage line settles

## Permissions
| Action | Permission |
|---|---|
| Dispatch (save a transfer in transit) and receive | `inventory.create` |
| Goods in Transit report, dispatch page | `inventory.view` |
| Cancel a dispatch, receipt or shortage | `inventory.delete` |

## Tests
`tests/Feature/Inventory/GoodsInTransitTest.php` has 3 tests. They cover:
- a dispatch moving stock into transit, kept at cost
- a part receipt with a shortage, and batches through transit
- pending quantities and the value as on a date
- over-receipt and early dates refused
- the dispatch cancel guard, and cancelling a receipt to put goods back in transit
- receiving the rest
- serial items received in full only
- the transit godown kept out of ordinary journals, choices and the default, and made only once
- the screens, CSV and permissions

## Manual checklist
1. Make a second godown. New stock journal → Godown transfer, tick Goods in transit, and send 30 of an item to it.
2. Stock summary by godown shows 30 under Goods in Transit. Goods in Transit report shows the dispatch and its value.
3. Open the dispatch. Receive 25 and enter 2 short. The page shows 3 still in transit, with links to the receipt and the shortage.
4. Try to receive 4 more. It is refused.
5. Try to cancel the dispatch. It is refused until the receipt and shortage are cancelled.
6. At 375 px, the dispatch page and the report have no sideways scroll.

## Done / partial / missing
- Done: two-step transfers through a transit godown, with part receipts, shortages, cancel rules, a report and exports.
- Partial: serial-numbered items must be received in full.
- Missing:
  - goods in transit on purchases: done in 11w (`docs/phase-11w-purchase-in-transit.md`)
  - freight or transit costs added to the stock value
