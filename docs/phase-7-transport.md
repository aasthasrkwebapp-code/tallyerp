# Phase 7 — Transport

Menu **Transport**: Trips, Trip & Vehicle Expenses, Vehicles / Drivers / Routes, Transport
Reports. Permissions `transport.view`, `transport.create`, `transport.edit` (also completes
trips), `transport.delete` (also cancels trips and expenses). The new *transport* role has them
plus masters and report viewing. Raising a freight bill also needs `sales.create`.

## Masters

* **Vehicles**: registration number (spaces and dashes removed, upper case, unique), type,
  make/model, capacity, own or hired, active flag.
* **Drivers**: name, phone, licence number and expiry (shown red once past). Each driver gets a
  **Driver Advance** ledger under Loans & Advances (Asset) for money given on trips.
* **Routes**: from, to, distance; the name defaults to "From – To".
* Masters with trips or entries cannot be deleted; mark them inactive.

## Trips

Number TR/0001…, vehicle, driver, route (fills from/to when left blank), customer, LR number,
material, weight, agreed freight, start date and odometer. **Mark completed** with the end date
and odometer (distance = end − start km). A trip with posted expenses or a live freight bill
cannot be cancelled until those are cancelled. The vehicle cannot change once expenses exist.

## Expenses (`App\Services\Transport\TransportService`)

Categories in `config/erp.php` (`transport_expense_categories`): fuel (with litres), toll,
driver allowance, loading/unloading, maintenance, tyres, permits/insurance, hire charges, other.
Each category posts to its own ledger under Direct Expenses (created on first use), or to any
expense ledger chosen.

* Paid from **cash or bank** → a payment voucher.
* Paid from a **driver advance** or a **supplier** on credit → a journal voucher.
* Recorded on a trip, or on the vehicle only (maintenance, insurance) without a trip.
* The voucher is locked to the expense; **cancel** the expense (with a reason) to reverse it.

Driver advances themselves are ordinary payment vouchers to the driver's advance ledger.

## Freight bills

**Raise bill** on a trip creates a posted sales invoice to the trip's customer: one line on the
Freight Income ledger (SAC 9965) with the chosen GST rate (0/5/12/18). Receivables, GST returns
and the statements pick it up like any other invoice. If the bill is cancelled the trip can be
billed again.

## Reports (`App\Services\Transport\TransportReports`)

All take a period, print and export CSV. Income is the taxable value of the posted freight bill
(or the agreed freight while unbilled); cancelled trips, expenses and vouchers are ignored.

* **Trip profit**: each trip's freight, expenses, profit and km; filter by vehicle.
* **Vehicles**: trips, km, freight, expenses by category (trip and non-trip), profit, cost/km.
* **Routes** and **Customers**: trips, km, freight, expenses, profit and margin.
* **Driver advances**: opening, advanced, spent/settled and balance still with each driver.

## Manual test checklist

1. Vehicles: add `mh 12 ab-1234` → saved as MH12AB1234; adding it again is refused.
2. Drivers: add Ramesh → ledger "Driver Advance - Ramesh" appears under Loans & Advances.
3. Routes: add Pune → Mumbai 150 km; the name fills itself.
4. Payment voucher: 5,000 cash to Driver Advance - Ramesh.
5. New trip on the route for a customer, start km 1000, freight 20,000.
6. Add expense: fuel 6,000 paid by cash (payment voucher), toll 800 from Ramesh's advance
   (journal). Add maintenance 1,500 on the vehicle without a trip.
7. Mark completed, end km 1300. Trip shows profit 13,200 and 300 km.
8. Raise bill at 5% → invoice of 21,000 opens from the trip; the trip shows "billed".
9. Reports: vehicle profit 11,700, cost/km 27.66; driver balance 4,200 Dr.
10. Cancel the toll expense → its journal is cancelled and trip profit becomes 14,000.
11. Open the screens on a phone: no sideways scrolling; tables turn into cards.
12. A user with the Sales role cannot open Transport; the Transport role cannot raise bills.

## Completed / partial / missing

* **Done**: vehicles, drivers with advance ledgers, routes, trips with odometer, expenses by
  category posting payment/journal vouchers, cancellation, freight bills as GST sales invoices,
  trip, vehicle, route, customer and driver reports with CSV, permissions, audit, mobile layouts.
* **Partial**: fuel tracks litres but not mileage per litre; hired-vehicle hire charges are an
  expense category, not a separate settlement with the owner.
* **Missing**: LR / consignment note printing, e-way bill numbers, one bill for several trips,
  vehicle document expiry reminders (insurance, permit, fitness), tyre and spare-part tracking.

Tests: `tests/Feature/Transport/TransportTest.php` (7 tests).
