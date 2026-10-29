# 11w. Goods in transit on purchases

A supplier's bill often arrives before the goods do. The bill has to be booked
so the supplier's balance and input tax are right, but the goods are not in
any godown yet. A purchase bill can now be booked with **Goods in transit**
ticked. Its stock waits in transit until it is received.

## How it works

- **Goods in transit** is a tick box on the purchase bill form, next to
  Reverse charge. The godown chosen on each line is where the goods are going.
- **When the bill is posted:**
  - the supplier, purchases and GST post as usual
  - each stock line goes into the company's **Goods in Transit** godown, so
    it is in closing stock at cost but in no real godown
  - lines that are against a receipt note are not affected, because the note
    already brought the goods in
  - service lines are not affected
- **Receiving the goods.** The bill page shows a **Goods in transit** box with
  bought, received, short and in-transit quantities for each line. Enter a
  date, a note, and for each line the quantity **received now** and **short
  now**. This posts:
  - a **transfer** from the transit godown into the line's godown, one for
    each destination godown
  - a **stock adjustment** that writes off the shortage from the transit
    godown at average cost
  - Both stock journals link back to the bill, and the bill lists them.
- Goods can be received in parts, on different dates. Batches keep their
  number through transit, and serial-numbered items are received in full, as
  with transfers in transit.
- **These are refused:**
  - receiving or writing off more than is still in transit
  - a date before the bill date
  - ticking Goods in transit on a bill with no stock lines
  - choosing the transit godown directly on a line
- **Altering or cancelling** the bill is refused once goods have been received
  or written off against it. Cancel those stock journals first; cancelling a
  receipt puts its goods back in transit.
- **Drafts** keep the tick. The stock moves when the bill is posted.
- **Goods in Transit report** (Reports menu) now lists purchase bills as well
  as transfers: bill number and date, supplier → godown, days in transit,
  item, quantities and value, with CSV, Excel and PDF. A bill's goods are
  valued at the bill's taxable value for the quantity still in transit;
  transfers stay at the item's cost.
- **Claiming a shortage from the supplier** is a debit note without stock
  lines. A debit note with stock returns goods from the line's godown, so
  receive the goods first.

## Data

Migration `2026_10_28_003500_add_goods_in_transit_to_purchases` adds:

- `invoices.in_transit`
- `invoice_items.transit_to_godown_id`: where the line's goods are bound.
  `godown_id` is then the transit godown.
- `stock_journals.dispatch_invoice_id`: the bill a receipt or shortage settles
- `stock_journal_lines.transit_invoice_item_id`: the bill line it settles

## Permissions

| Action | Permission |
| --- | --- |
| Book a bill in transit | `purchases.create` / `purchases.edit` |
| Receive, write off shortage | `inventory.create` |
| Goods in Transit report | `inventory.view` |
| Cancel a receipt or shortage | `inventory.delete` |

## Tests

`tests/Feature/Inventory/PurchaseInTransitTest.php`:

- `test_bill_in_transit_received_in_parts_with_a_shortage`:
  - lines go to the transit godown, bound for their own godowns
  - the stock value includes the goods in transit
  - report rows, from → to and value, and nothing before the bill date
  - over-receipt, early dates and empty receipts refused
  - a part receipt to two godowns with a shortage; batches kept
  - altering and cancelling the bill refused while receipts stand
  - cancelling a receipt puts goods back in transit
  - receiving the rest
  - altering the bill to arrive straight away once receipts are cancelled
- `test_rules_for_ticking_goods_in_transit`: no stock lines, the transit
  godown chosen directly, drafts posted later
- `test_screens_and_permissions`: the form tick box (purchases only), the
  bill page, the report and its CSV, receiving from the bill page, the
  journal banner, and a viewer who can look but not receive

## Manual checklist

1. Make a second godown. New purchase bill with two stock items, one to each
   godown, and a service line. Tick Goods in transit and save.
2. The bill shows "Goods in transit". Stock summary by godown shows the items
   under Goods in Transit; the Goods in Transit report shows the bill.
3. On the bill, receive part of one line and enter a shortage. Check the two
   stock journals and the godown quantities.
4. Try to alter the bill. It is refused until the stock journals are
   cancelled.
5. Receive the rest. The badge changes to Received and the report is empty.
6. At 375 px, the bill page and the report have no sideways scroll.

## Not done

- Freight or other transit costs are not added to the stock value.
