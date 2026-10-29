# Phase 10r: Salary payment, bank transfer file, PF ECR and ESI file

## What it does

A posted payroll run has a **Payment & files** button on its pay sheet. It opens a page with four parts.

### Salary payment
- **Record payment** posts a payment voucher: Salary Payable debited, the chosen bank or cash ledger credited with the run's net pay. It defaults to the first bank ledger.
- The payment can't be dated before the payroll. It can be recorded once.
- The pay sheet then shows "paid by" with the voucher. Until then it shows "salary not paid yet".
- **Cancelling the payroll also cancels its payment voucher.**

### Bank transfer list
- Lists each employee with net pay, with their bank, account number and IFSC. Download as CSV or Excel for the bank's bulk upload. The narration is "Salary Oct 2026".
- Employees with no account number, or a missing or invalid IFSC, are **left out and listed** with the reason and a link to edit them.

### PF ECR (EPFO electronic challan cum return, version 2)
- A text file with one member per line, fields joined by `#~#`: UAN, name, gross wages, EPF wages, EPS wages, EDLI wages, EE share, EPS, ER EPF (employer's share less EPS), NCP days, refund of advances.
- Amounts are in whole rupees.
- **EPF wages** are the wages PF was worked on: earned basic, up to the PF wage ceiling (15,000).
- **EPS and EDLI wages** are EPF wages up to the ceiling. EPS wages are nil from the month the member turns 58, taken from the date of birth.
- **EPS** is 8.33% of EPS wages, rounded. It is never more than the employer's share, and the rest goes to EPF.
- **NCP days** are the days in the month less the paid days.
- Names are upper case, with letters, spaces and dots only.
- Members without a 12-digit UAN are left out and listed.

### ESI monthly contribution
- An Excel file in the ESIC upload layout: IP number, IP name, days paid, total monthly wages, reason code (0, or 1 when no days were paid), last working day.
- Only employees with ESI in the payroll are included. Those without a valid ESI number are left out and listed.

The files can be made only from posted payroll.

## Data

Migration `2026_10_28_001300_add_payment_voucher_to_payroll_runs` adds `payroll_runs.payment_voucher_id`.

## Permissions

| Action | Permission |
|---|---|
| View the page | `payroll.view` |
| Download the bank, ECR and ESI files | `payroll.view` and `reports.export` |
| Record the salary payment | `payroll.edit` |

## Tests

`tests/Feature/Payroll/PayrollFilesTest.php` has 5 tests. They cover:
- the bank list and the employees left out of it
- ECR figures for a member under the ceiling and one over it, with part-month NCP days
- the exact ECR text, nil EPS at 58, and a missing UAN
- ESI rows
- the payment voucher's entries, refusals (draft, wrong ledger, paid twice), and cancelling with the payroll
- the screens, downloads and permissions

## Manual checklist

1. Give an employee a UAN, an ESI number, a bank account and an IFSC. Leave a second employee without bank details. Post a month's payroll.
2. Open Pay sheet → Payment & files. The second employee shows under "Left out of the file", with a link to edit them.
3. Download the ECR text file. Check that each line has 11 fields joined by `#~#`, and that the totals match the screen.
4. Download the bank list (CSV or Excel) and the ESI Excel file.
5. Record the payment from the bank. Check the payment voucher, and that the pay sheet shows "paid by".
6. Cancel the payroll. Check that the payment voucher is cancelled too.
7. At 375 px, the page has no sideways scroll.

## Done / partial / missing

- Done: the salary payment voucher, the bank transfer list, the PF ECR v2 file and the ESI contribution file, each with missing details reported.
- Missing:
  - bank-specific fixed-width or H2H formats
  - PF arrears and refund-of-advance lines
  - ESI last working day for leavers (left blank)
  - EPS eligibility for members who joined after September 2014 with wages above the ceiling (they are treated as eligible)
  - Form 16 and the TDS returns
