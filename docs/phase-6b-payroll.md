# Phase 6b — Payroll

Menu **Payroll**: Employees, Pay Heads, Monthly Payroll. Permissions `payroll.view`,
`payroll.create`, `payroll.edit` (also needed to post), `payroll.delete` (also cancels posted
payroll). The *employee* (payroll staff) role has all of them; Viewer can view.

## Masters

* **Employees**: code, name, designation, department, joining and leaving dates, PAN, PF UAN,
  ESI number, bank account and IFSC (validated), contact details, and whether PF, ESI and
  professional tax apply. Each employee has a **salary structure**: the pay heads that apply and
  the monthly amount for fixed heads. Basic salary must be included.
* **Pay heads** (created with each company, all editable):

  | Pay head | Type | Calculation | Ledger |
  |---|---|---|---|
  | Basic Salary | Earning | fixed, by paid days | Salaries & Wages |
  | House Rent Allowance | Earning | 40% of basic | Salaries & Wages |
  | Special Allowance | Earning | fixed, by paid days | Salaries & Wages |
  | Employee PF | Deduction | 12% of basic up to ₹15,000 | PF Payable |
  | Employee ESI | Deduction | 0.75% of gross while gross ≤ ₹21,000 | ESI Payable |
  | Professional Tax | Deduction | state slab | Professional Tax Payable |
  | TDS on Salary | Deduction | fixed per employee | TDS on Salary Payable |
  | Employer PF | Employer | 12% of basic up to ₹15,000 | Employer PF Contribution → PF Payable |
  | Employer ESI | Employer | 3.25% of gross | Employer ESI Contribution → ESI Payable |

  Ledgers are created under Indirect Expenses, Current Liabilities and Duties & Taxes.
  Pay heads in use cannot be deleted (mark them inactive).

## Calculation rules (`App\Services\Payroll\PayrollCalculator`)

* Prorated heads × paid days ÷ days in the month (half days allowed).
* PF rounded to the rupee; ESI rounded up to the rupee. ESI coverage is decided on the monthly
  gross in the salary structure; the contribution is on the gross actually earned.
* Professional tax slabs are in `config/erp.php` (`payroll.professional_tax`) for Maharashtra
  (₹300 in February), Karnataka, West Bengal, Gujarat and Telangana. Other states pay none until
  slabs are added there. **Check the slabs against current state rules before relying on them.**
* The PF wage ceiling and ESI limit are in the same config section. Employer PF is shown as one
  12% figure; the EPS (8.33%) / EPF split is not shown separately.

## Monthly payroll (`App\Services\Payroll\PayrollService`)

1. **Prepare** a month: a draft payslip for every active employee on the rolls in that month;
   paid days default to the days employed (mid-month joiners and leavers are prorated).
2. Edit **paid days** on the pay sheet and **Recalculate** (also picks up structure changes).
3. **Post**: one journal voucher dated the month end (or the date chosen):
   Dr each earning's expense ledger and each employer contribution's expense ledger;
   Cr each deduction's payable ledger, each employer contribution's payable ledger, and
   **Salary Payable** with the net pay. The voucher is locked to the payroll (cancel it there).
4. **Pay**: make payment vouchers Dr Salary Payable / Cr bank, and Dr PF/ESI/PT/TDS payable when
   depositing them.
5. **Cancel** a posted payroll with a reason: its voucher is cancelled and the month can be
   prepared again. Drafts can be deleted.

Pay sheet: one column per pay head with totals and CSV export; printable payslip per employee
with net pay in words; employee page lists payslip history.

## Manual test checklist

1. Pay Heads: check the defaults; try to point a deduction at an expense ledger (refused).
2. New employee with basic 12,000 (HRA, PF, ESI, PT ticked by default); PAN in lower case is
   saved in capitals.
3. Monthly Payroll → prepare last month → gross 16,800, PF 1,440, ESI 126, PT 200 (Maharashtra).
4. Change paid days to 15 → Recalculate → earnings halve.
5. Post → open the journal from the link; it balances; Salary Payable shows the net pay.
6. Print a payslip; open on a phone (no sideways scrolling, pay sheet rows become cards).
7. Cancel the payroll with a reason → the journal is cancelled; prepare the month again.

## Completed / partial / missing

* **Done**: employees, pay heads, salary structures, attendance as paid days, PF/ESI/PT/TDS,
  proration for joiners and leavers, pay sheet, payslips, posting to accounts, cancellation,
  permissions, audit of employees, pay heads and payroll runs.
* **Partial**: TDS is a fixed monthly amount entered per employee (no income-tax projection);
  professional tax for five states.
* **Missing**: daily attendance and leave registers, overtime, loans and advances recovery,
  arrears, bonus and gratuity, PF ECR / ESI return files, Form 16, salary bank transfer file.

Tests: `tests/Feature/Payroll/PayrollTest.php` (8 tests).
