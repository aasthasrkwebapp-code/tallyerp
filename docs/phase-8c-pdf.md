# Phase 8c — PDF documents and report PDFs

PDFs are produced with [laravel-dompdf](https://github.com/barryvdh/laravel-dompdf) 3.1
(dompdf 3.1, PHP 8.1+; installed and tested on PHP 8.2 and 8.3 in CI; the lock is resolved for PHP 8.2). Templates
are in `resources/views/pdf/` and use the DejaVu Sans font bundled with dompdf (it has the ₹ sign).

| Document | Where | Notes |
|---|---|---|
| Sales invoice, purchase bill, credit/debit note | Invoice page → **PDF**, and on the print page | Same layout as the print view: GST summary by HSN and rate, amount in words, bank details, terms, footer, logo; DRAFT/CANCELLED stamp. Downloads are audited. |
| Payslip | Payslip page → **PDF** | A4, earnings and deductions side by side, net pay in words. |
| Every report with CSV/Excel | **PDF** button next to them | Company name, GSTIN, report title and period, numbers right-aligned with Indian grouping; landscape and smaller type for wide reports; page numbers and print time in the footer. |

Profit & Loss and Balance Sheet now also export (CSV, Excel and PDF) as vertical statements:
each top-level group with the groups and ledgers directly under it, gross and net profit, and
the balance sheet totals.

Report PDFs need `reports.export`, like CSV. Invoice PDFs need view permission on that kind of
invoice; another company's invoice returns 404.

## Manual test checklist

1. Open a posted sales invoice → PDF → open the file: header, party, items, HSN summary and
   totals match the screen; a draft shows a DRAFT stamp.
2. Payroll → a month → Payslip → PDF.
3. Trial balance → PDF; Profit & Loss → PDF shows gross and net profit; Balance sheet → PDF.
4. Transport → Vehicles report → PDF prints landscape with every column.
5. A user with the Sales role cannot download report PDFs.

## Completed / partial / missing

* **Done**: invoice, credit/debit note and purchase bill PDFs; payslip PDF; PDF of every
  exportable report including P&L and balance sheet; permissions; tests.
* **Partial**: report PDFs are plain tables from the export rows (the on-screen two-column
  Tally-style P&L and balance sheet are printed from the browser).
* **Missing**: e-mailing invoices and reminders as PDF attachments, custom invoice templates,
  digital signatures, e-invoice QR code (needs IRN integration).

Tests: `tests/Feature/Data/PdfTest.php` (3 tests).
