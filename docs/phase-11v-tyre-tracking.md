# 11v. Tyre tracking

Transport > Tyres keeps a register of every tyre the company owns. It records
which vehicle and wheel position each tyre is on, the km it has run, what it
has cost per km, and when it reaches its expected life.

## The register

Each tyre has:

- a serial number, unique within the company
- brand, size and supplier
- purchase date and cost
- expected life in km
- retreads (count and total cost)
- notes

Its status is **In stock**, **Fitted** or **Scrapped**.

The list filters by status, by vehicle, or by **Life reached**. It shows the
vehicle and position, km run, expected km, retreads, total cost and cost per
km, and exports to CSV or Excel.

## Fitting and removal

- **Fit to a vehicle** takes the vehicle, wheel position, date and odometer
  reading. The positions are FL, FR, RLO, RLI, RRO, RRI, the second rear axle
  and Spare; another code of up to 12 characters can be typed in.
  - A tyre that is already fitted or scrapped is refused.
  - A position already taken on that vehicle is refused, naming the tyre on
    it. Spare is the exception, since a vehicle can carry more than one.
  - The date cannot be before the purchase date, before the tyre's last
    removal, or in the future.
- **Remove** takes the date, the odometer reading and a reason:
  - rotation, puncture, retread, worn, damage, vehicle sold or off road, or
    other
  - the date cannot be before fitting, and the km cannot be below the fitting
    reading
  - ticking **Scrap it** scraps the tyre in the same step
- **Retread** adds the cost to the tyre and counts the retread. Only a tyre in
  stock can be retreaded.
- **Scrap** needs a date and a reason and is only possible from stock. It
  needs the transport delete permission.

Every fitting, removal, retread and scrap is in the tyre's history and in the
audit log.

## Km and cost per km

- **Km run** adds up every completed fitting (removal km minus fitting km).
- **A tyre still fitted** counts up to the vehicle's latest odometer reading,
  which is the highest trip end km (or start km) on a non-cancelled trip.
- **Cost per km** is the purchase cost plus retread costs, divided by the km
  run.
- **Life reached** flags any tyre that is not scrapped and has run at least
  its expected km.

## Permissions

| Action | Permission |
| --- | --- |
| View the register and a tyre | `transport.view` |
| Add a tyre | `transport.create` |
| Edit, fit, remove, retread | `transport.edit` |
| Scrap | `transport.delete` |

## Tests

`tests/Feature/Transport/TyreTest.php`:

- `test_tyre_life_cost_per_km_and_rules`:
  - fitting rules: position taken, purchase date, odometer
  - km counting while fitted
  - removal rules
  - a retread, then a fitting on another vehicle, then scrap
  - cost per km
  - the life flag ignores scrapped tyres
- `test_screens_alerts_export_and_permissions`:
  - list filters, the Life reached flag, the tyre page and the CSV row
  - a viewer can look but not add, fit or remove

## Manual checklist

1. Add a tyre with a cost and an expected life, then fit it to a vehicle at RLO.
2. Try to fit a second tyre at RLO on the same vehicle; it is refused.
3. Record a trip with an end km. The tyre's km run goes up.
4. Remove the tyre (reason: retread), retread it, and fit it to another vehicle.
5. Check the cost per km and the history, then export the list.
6. Remove the tyre, tick Scrap it, and check it leaves the Life reached filter.

## Not done

- Tyre purchases are not linked to purchase invoices; the cost is typed in.
- Odometer readings come from trips only; there is no separate odometer log.
