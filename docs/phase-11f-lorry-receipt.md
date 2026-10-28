# 11f Lorry receipt (LR) printing and e-way bill on trips

## What it does

- A trip now carries the **lorry receipt** details: LR number and date, consignor and consignee
  (name, address, GSTIN), packages and packing, goods invoice number and value, freight terms
  (Paid, To pay, To be billed), and the **e-way bill** number (12 digits) with its valid-till date.
- **LR numbering**: on a new trip with the LR number left blank, "give the next LR number"
  (ticked by default) assigns LR/0001, LR/0002, ... A number typed in (e.g. from a pre-printed
  book) is kept as it is. The LR date defaults to the trip start date.
- **Print LR** on the trip page opens the consignment note with Consignor, Consignee, Driver and
  Office copies, one per page (`?copies[]=Driver copy` for one copy), and a PDF download
  (`?format=pdf`, A4). The consignor defaults to the customer billed for freight.
- **Checks and warnings**: GSTINs are checked; the e-way bill must have 12 digits and cannot
  run out before the trip starts; saving a trip with goods worth more than 50,000 and no e-way
  bill shows a warning; an open trip whose e-way bill has run out shows "(expired)".
- Cancelled trips have no LR.

## Permissions

- Print: `transport.view`. Edit fields: `transport.create` / `transport.edit` as for trips.

## Files

- Migration `2026_10_28_002500_add_lorry_receipt_to_trips.php`.
- `Trip::FREIGHT_TERMS`, `Trip::consignor()`, `Trip::ewayBillExpired()`,
  `TransportService::nextLrNumber()`, `TripController::lr()`, view `pdf/lr.blade.php`.
- Tests: `tests/Feature/Transport/LorryReceiptTest.php` (2).

## Manual checklist

1. New trip for a customer with the LR number blank: it gets LR/0001.
2. Fill consignee, 6 coils, invoice value 4,80,000, To pay, and an e-way bill number.
3. Print LR: four copies, consignor is the customer, the e-way bill shows in groups of four.
4. Download the PDF.
5. Save a trip with goods worth 75,000 and no e-way bill: a warning shows.

## Done / partial / missing

- Done: LR fields, numbering, print and PDF, e-way bill number and validity checks, tests.
- Missing: generating the e-way bill on the government portal (enter the number it gives),
  several consignments on one trip.
