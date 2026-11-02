# 11l. GSTR-2B matching

Open it from GST > GSTR-2B Matching. On the GST portal, go to Returns >
GSTR-2B, download the JSON for the month, and load it here (needs
`gst.create`).

## Loading

- The file must be the portal's GSTR-2B JSON, with `data.gstin`,
  `data.rtnprd` (MMYYYY) and `data.docdata`.
- If the company has a GSTIN, the file's GSTIN must be the same.
- One statement is kept per month (`gstr2b_returns`). Loading the month again
  replaces it. A statement can be removed (needs `gst.edit`).
- Sections read:
  - **b2b:** suppliers' invoices. These are matched to *purchase bills*.
  - **cdnr:** credit and debit notes. A supplier's credit note (typ C) is
    matched to a *debit note* in the books, and a debit note (typ D) to a
    purchase bill.
- Document amounts are taken from the document's `txval / igst / cgst / sgst
  / cess`, or from the sum of its items.

## Matching (`App\Services\Gst\Gstr2bMatching`)

1. **By number:** the same supplier GSTIN, the same kind, and the same
   document number. Numbers ignore case and punctuation, and leading zeros
   in each run of digits, so `SS/0101` = `ss-101`.
   - If the date, taxable value and each tax agree within ₹1, the row is
     **Matched**.
   - Otherwise it is a **Mismatch**, with each difference spelled out.
2. **Probable:** no number match, but the same GSTIN, kind and date, with
   amounts that agree. The number usually differs because of a typing
   mistake.
3. **In 2B, not in books:** the supplier reported a document that has not
   been entered (or is still a draft).
4. **In books, not in 2B:** a posted bill or debit note dated in the month,
   with GST, from a supplier with a GSTIN, that no 2B document matched. The
   supplier may not have filed, or filed under another month.

Bills from earlier months are found too, because 2B carries documents by the
supplier's filing period. Each bill in the books is matched at most once.

The summary cards count each status and filter the table. It also shows:

- ITC as per 2B: invoices and debit notes, less credit notes
- tax that 2B marks as not available (`itcavl = N`)

The table exports to CSV.

## Manual checklist

1. Load a 2B JSON for a month where some bills are entered correctly. They show as Matched.
2. Change the IGST of one bill in the books, and reload or refresh. It shows Mismatch with the IGST difference.
3. Mistype a bill number. It shows Probable, with both numbers.
4. A supplier invoice that was not entered shows In 2B, not in books. A bill with no 2B entry shows In books, not in 2B.
5. Loading a file for another GSTIN is refused. A viewer can see the results but cannot load or remove a statement.

## Done / partial / missing

- **Done:** loading and validation, b2b and cdnr, matching in five categories, ITC totals, filters, CSV, permissions, tests (`tests/Feature/Gst/Gstr2bMatchingTest.php`).
- **Partial:** the tolerance is fixed at ₹1. Matching on place of supply and reverse charge is not checked, but RCM is shown.
- **Missing:**
  - amendment sections (b2ba, cdnra), ISD, and imports of goods (impg, impgsez)
  - marking a mismatch as accepted, or deferring ITC
  - fetching 2B through the GSP API
