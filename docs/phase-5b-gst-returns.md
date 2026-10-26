# Phase 5b — GST return working papers

Menu **GST**: GSTR-1, GSTR-3B, Purchase Register. All are built from posted invoices, credit
notes, purchase bills and debit notes (drafts and cancelled documents are ignored). Pick a
return month or any date range. Code: `App\Services\Gst\GstReports`.

## GSTR-1 (outward supplies)

* **4A B2B**: invoices to parties with a GSTIN (regular / composition), one line per GST rate,
  with place of supply and state code.
* **5 B2C large**: inter-state invoices to unregistered buyers above ₹1,00,000.
* **6 Exports / SEZ**: parties marked Overseas or SEZ.
* **7 B2C small**: all other unregistered sales, summed by place of supply and rate.
* **9B Credit notes** to registered (CDNR) and unregistered (CDNUR) parties, with the original
  invoice number and date.
* **12 HSN summary**: quantity, value, taxable value and tax by HSN/SAC, rate and unit, net of
  credit notes. Lines without HSN are highlighted.
* **13 Documents issued**: number range, total and cancelled count per voucher type.
* CSV download for every section, laid out like the portal's offline-tool columns.

## GSTR-3B

* **3.1** outward taxable supplies, zero-rated supplies (exports/SEZ) and nil-rated (0%) supplies,
  net of credit notes.
* **4** ITC from purchase bills, reversed by debit notes, and the net credit.
* **6.1** indicative set-off: IGST credit first (IGST → CGST → SGST), CGST credit against CGST then
  IGST, SGST credit against SGST then IGST; CGST and SGST credit never cross. Shows the cash
  payable per head and unused credit.

## Purchase register

Purchase bills and debit notes with supplier invoice number/date, supplier GSTIN and tax, for
matching with GSTR-2B; CSV download (debit notes negative).

## Tests

`tests/Feature/Gst/GstReportsTest.php` builds a month of sales and purchases and checks every
section, the 3B totals and the set-off (hand-computed: IGST 27,180 liability → 26,362 in cash).

## Manual testing checklist

1. Post a B2B sale (customer with GSTIN, same state), an inter-state B2B sale, a sale of more
   than ₹1,00,000 to an unregistered buyer in another state, a small local consumer sale, and
   a credit note against the first invoice.
2. **GSTR-1** for the month: each lands in its section; B2C small is summed by state and rate.
3. **HSN summary** = sales minus the credit note.
4. Cancel an invoice → it disappears from the sections and is counted as cancelled in
   *Documents issued*.
5. Post purchase bills and a debit note → **GSTR-3B** ITC and set-off; **Purchase Register** lists them.
6. Download each CSV and open it in a spreadsheet.

## Known limitations

* Working papers only: no JSON upload file, no direct GSTN filing, no GSTR-2B import/matching.
* No reverse charge, cess, advances received (11A/11B), amendments, or e-invoice (IRN) / e-way bill.
* ITC brought forward from earlier months, interest and late fee are not computed.
