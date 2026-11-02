# 11k. Multi-trip freight bills

Open it from Transport > Trips > **Bill several trips**, or from the link
under a trip's freight bill box. It needs `transport.edit` and `sales.create`.

## How it works

- Pick a customer. The page lists that customer's **completed** trips that
  are not yet billed. A trip whose bill was cancelled counts as not billed.
- Tick the trips and adjust each trip's freight if needed. Choose the bill
  date (by default, the last trip's end date in the service) and the GST
  rate (0, 5, 12 or 18%).
- **Raise one bill** posts one sales invoice to the customer:
  - It has one line per trip: SAC 9965 on Freight Income, unit "Trip", and
    a description with the route, vehicle, LR, material and date.
  - The reference is "N trips first–last" and the narration lists the
    trips.
  - Each trip's `invoice_id` points to the bill, and its `freight_amount`
    becomes the amount billed for it.
- Single-trip billing on the trip page now uses the same code
  (`TransportService::billTrips`).
- **Refusals:**
  - trips of different customers
  - a trip already billed, a cancelled trip, or a trip without a customer
  - a zero freight
  - no trips ticked
- **Reports:**
  - A bill that covers one trip still counts its taxable value.
  - A bill that covers several trips counts each trip's own billed freight,
    so the freight is not counted twice.
- The trip page shows "One bill for N trips" with links to the other trips.
- Cancelling the invoice frees all its trips for billing again.

## Manual checklist

1. Complete three trips for one customer. Under Bill several trips, tick two, change one freight, and raise the bill. The invoice has two lines.
2. Each trip's page shows the shared bill. The trip profitability report shows each trip's own freight.
3. The third trip is still listed and can be billed alone.
4. Cancel the shared invoice. Both trips are listed again.
5. As a viewer, the button is not shown and the page is refused.

## Done / partial / missing

- **Done:** multi-trip bills, report freight per trip, refusals, screen, tests (`tests/Feature/Transport/MultiTripBillTest.php`).
- **Partial:** one GST rate for the whole bill.
- **Missing:** removing one trip from a posted multi-trip bill (cancel and raise it again instead); monthly automatic billing of a customer's trips.
