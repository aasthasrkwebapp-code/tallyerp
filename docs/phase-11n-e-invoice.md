# 11n. E-invoice (IRN) JSON

Businesses over the e-invoicing turnover limit must report B2B invoices,
credit notes and exports to the Invoice Registration Portal (IRP). The app
does not call the IRP. It prepares the JSON in the IRP's INV-01 schema
(version 1.1), and keeps the IRN, acknowledgement and signed QR code that the
IRP returns.

## Which documents

A posted sales invoice or credit note needs an e-invoice when:

- the customer has a GSTIN and the document carries GST, or
- it is an export (an overseas customer).

B2C invoices, drafts and cancelled documents are left out.

## On the invoice

A posted document has an **E-invoice** box (needs `gst.view`; saving the IRN
needs `gst.create`, cancelling it needs `gst.edit`):

- **Problems are listed first:**
  - the company has no GSTIN, PIN code or state
  - the customer has no GSTIN, PIN code or state (overseas customers are exempt)
  - a line has no HSN code
- **JSON for the IRP** downloads the document in INV-01 form:
  - TranDtls (B2B or EXPWOP/EXPWP, reverse charge)
  - DocDtls (INV or CRN, number, date)
  - SellerDtls and BuyerDtls (GSTIN, legal name, address, PIN, state code,
    place of supply)
  - ItemList (HSN, goods or service, quantity, unit, rate, discount,
    assessable value, GST rate, IGST/CGST/SGST, cess, line total)
  - ValDtls (totals, round-off, invoice value)
- **After upload**, enter the 64-character IRN, the acknowledgement number and
  date, and the signed QR code. The IRN must be 64 hexadecimal characters and
  is unique within the company.
- **Cancel IRN** records the cancellation date (the IRP allows this within 24
  hours). The print then stops showing the IRN and QR code.

## Print and PDF

When the IRN is active, the invoice print and the PDF show the IRN, the
acknowledgement number and date, and the QR code made from the signed QR
text. The e-way bill number, when entered, is printed too.

## GST > E-invoices

This page lists a month's documents that need an e-invoice, with their
problems and the IRN or *Pending*. Ticked rows download together as one JSON
array, which the IRP's bulk upload tool accepts.

## Audit

Saving and cancelling an IRN are recorded in the audit log. The signed QR text
is left out of the audit record because of its size.

## Tests

`tests/Feature/Gst/EInvoiceTest.php`:

1. Which documents apply (B2B invoice and credit note yes, B2C no), and the
   JSON for a B2B invoice: seller, buyer, items, tax split, totals, CRN for a
   credit note and the bulk array.
2. Registering an IRN checks its format, refuses a B2C invoice and a
   duplicate IRN, and stores it in lower case.
3. The invoice box, the monthly list, the JSON download, saving and
   cancelling an IRN, the IRN and QR on the print and the PDF, and a viewer
   being refused.

## Manual checklist

1. Post a sales invoice to a registered customer and open it.
2. Fix any problems in the E-invoice box, then download the JSON.
3. Enter a 64-character IRN, ack number and date, and a QR text. Print the
   invoice and check the IRN block and the QR code.
4. Cancel the IRN and print again: the block is gone.
5. Open GST > E-invoices, tick several rows and download the JSON.
6. Repeat on a phone-width screen.

## Done, partial, missing

- **Done:** INV-01 JSON for invoices, credit notes and exports, single and
  bulk; problem checks; IRN, ack and QR stored; IRN and QR on print and PDF;
  cancellation.
- **Partial:** debit notes raised as sales-side documents are not covered
  (the app's debit notes are purchase-side).
- **Missing:** direct IRP API calls (needs a GSP account), e-way bill
  generation through the IRP.
