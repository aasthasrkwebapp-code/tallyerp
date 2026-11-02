# 11m. E-way bills (bulk-generation JSON)

An e-way bill is needed for goods moving under a consignment worth more than
₹50,000 (rule 138). The app does not call the portal. It prepares the JSON
that the e-way bill portal accepts under **Generate Bulk** (format version
1.0.0621), and keeps the e-way bill number once the portal issues it.

## On a sales invoice

A posted sales invoice has an **E-way bill** box (needs `gst.view`; editing
needs `gst.create`):

- **Transport (Part B):**
  - mode: road, rail, air or ship
  - distance in km (1–4,000)
  - vehicle number (spaces and dashes are removed, and the format is
    checked: MH12AB1234, BH series, or temporary TR numbers) and vehicle type
  - transporter ID (15 characters) and name
  - LR, RR or AWB number and date
- **Rules:**
  - By road, a vehicle number or a transporter ID is required. With a
    transporter ID, the transporter can add the vehicle later.
  - Rail, air and ship need the transport document number.
- After the portal issues the e-way bill, enter its 12-digit number, date and
  validity. The box shows the validity and an *Expired* badge once it has
  passed.
- **JSON for the portal** downloads a single-invoice file.
- **Problems are listed above the form:**
  - the company has no GSTIN, PIN code or state
  - the customer has no PIN code (overseas customers are exempt)
  - a line has no HSN code
- **Needed:** the box flags an invoice when it is over ₹50,000 and has goods,
  meaning a stock item or an HSN that is not SAC 99.

## GST > E-way Bills

This page lists a month's posted sales invoices that need an e-way bill, or
that have details. A filter shows all sales invoices. Each row shows the
transport, any problems, and the e-way bill number or *Pending*. Invoices that
have details but no number are ticked. **Download JSON** makes one bulk file
for them. Downloads are recorded in the audit log.

## JSON mapping

| Field | Value |
|---|---|
| supplyType / subSupplyType / docType | O / 1 (supply), or 3 (export) for an overseas customer / INV |
| docNo, docDate | invoice number, dd/mm/yyyy |
| from… | company GSTIN, legal name, first address line and the rest, city, PIN, state code |
| to… | customer GSTIN, or URP when unregistered; shipping or billing address, city, PIN; state from the place of supply (99 and PIN 999999 for exports) |
| totalValue, cgst/sgst/igst/cessValue, OthValue, totInvValue | invoice totals; round off goes in OthValue |
| transMode, transDistance, vehicle…, transporter…, transDoc… | the saved details |
| itemList | each line: HSN, quantity, UQC from the unit (OTH when none), taxable value, and rates split CGST/SGST or IGST |
| mainHsnCode | HSN of the largest line |

## Manual checklist

1. Post a ₹60,000 sales invoice of goods to a customer in another state. The box says *Needed*.
2. Save road, 380 km, and vehicle `mh 12 ab 1234`. It is stored as MH12AB1234. Download the JSON and validate it on the portal's bulk-upload screen.
3. Enter the issued number and a past validity date. The list and the invoice show *Expired*.
4. Remove the customer's PIN code. The problem is shown, and the JSON is refused.
5. As a viewer, the details show, but there is no form or JSON button.

## Done / partial / missing

- **Done:** details, checks, single and bulk JSON, e-way bill number and validity, monthly list, audit, permissions, tests (`tests/Feature/Gst/EwayBillTest.php`).
- **Partial:**
  - sales invoices only
  - the dispatch-from and ship-to addresses are the company's and the customer's (bill-from / dispatch-from combinations are not offered)
  - validity is entered, not calculated
- **Missing:** generation, cancellation and Part B updates by API; e-way bills for sales returns, job work, branch transfers and delivery notes; the consolidated e-way bill.
