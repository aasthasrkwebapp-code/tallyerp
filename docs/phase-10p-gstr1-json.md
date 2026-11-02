# Phase 10p: GSTR-1 JSON for the GST portal

## What it does

GST → GSTR-1 → **JSON for the GST portal** downloads `GSTR1_<GSTIN>_<MMYYYY>.json`. The file can be opened in the GST offline tool or uploaded on the portal.

It is built from the same working papers as the GSTR-1 screen.

| Table | JSON section | Contents |
|---|---|---|
| 4A / 4B | `b2b` | Invoices to registered buyers, grouped by GSTIN. Rate-wise lines, reverse charge flag, place of supply. |
| 6B / 6C | `b2b` (inv_typ SEWP / SEWOP) | SEZ supplies, with or without IGST |
| 5 | `b2cl` | Inter-state invoices to unregistered buyers above ₹1,00,000, grouped by place of supply |
| 6A | `exp` (WPAY / WOPAY) | Exports, with or without IGST |
| 7 | `b2cs` | Other B2C supplies, by place of supply and rate (INTRA / INTER), less credit notes to small unregistered buyers |
| 8 | `nil` | 0% lines on domestic supplies, by supply type |
| 9B | `cdnr` | Credit notes to registered buyers |
| 9B | `cdnur` | Credit notes against B2C large invoices (B2CL) and exports (EXPWP / EXPWOP) |
| 12 | `hsn` | HSN summary with the unit's UQC (from the unit master), quantity, rate and tax, net of credit notes |
| 13 | `doc_issue` | Invoice and credit note number ranges, totals and cancelled counts |

Amounts are in rupees with two decimals, and dates are dd-mm-yyyy. Empty sections are left out, as the offline tool does.

**The file is refused, with the reasons shown on the GSTR-1 screen, when:**
- the company GSTIN is missing or invalid
- the period is not a whole month (or a whole quarter for quarterly filers). The return period (`fp`) is the last month.
- a B2B buyer or credit note has an invalid GSTIN
- an item in the HSN summary has no valid HSN / SAC code

## Data

No new tables.

## Permissions

| Action | Permission |
|---|---|
| Download the JSON | `gst.view` and `reports.export` |

## Tests

`tests/Feature/Gst/GstReportsTest.php` adds two tests:
- the JSON for the worked September example, covering B2B, B2CL, B2CS, nil, CDNR, HSN and documents, plus the period and GSTIN checks
- the download, its file name, the refusal for a part month, and permissions

## Manual checklist

1. Set the company GSTIN. Post sales to a registered buyer, a small walk-in sale and a credit note.
2. Open GSTR-1 for the month and press **JSON for the GST portal**. The downloaded file opens in the GST offline tool and shows the same totals as the screen.
3. Choose a part of a month and press the button again. A message explains that a whole month is needed.

## Done / partial / missing

- Done: the GSTR-1 JSON for tables 4–9B, 12 and 13.
- Missing:
  - amendments (9A, 9C, 10, 11)
  - advances received and adjusted (11A / 11B)
  - shipping bill details for exports, which are not captured on invoices yet
  - separating nil-rated, exempt and non-GST supplies (all 0% lines are reported as nil-rated)
  - debit notes issued to customers
  - GSTR-3B JSON
