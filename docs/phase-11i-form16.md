# 11i. Form 16

Open it from Payroll > Form 16. The page has a year selector and one row per
employee paid in that year: PAN, regime, gross salary, taxable income, tax
payable, TDS deducted, and the shortfall or excess. The list exports to CSV.

## Where the figures come from

- **Salary:** the earnings lines of **posted** payrolls whose month falls in
  the financial year, by pay head, plus leave encashments paid in the year.
  Draft and cancelled payrolls are left out.
- **Professional tax and TDS:** the payslip lines of the `pt` and `tds` pay
  heads.
- **Part A:** the amount paid and the tax deducted in each quarter (Q1
  Apr–Jun … Q4 Jan–Mar). Below the list, you can enter the receipt
  (acknowledgement) number and filing date of each quarter's Form 24Q
  return. These print in Part A. Clearing a box removes that quarter's
  number.
- **Tax declaration:** entered per employee per year under *Declaration*. It
  holds:
  - the regime (new is the default)
  - HRA and other section 10 exemptions
  - home loan interest and other income
  - 80C, 80CCD(1B), 80D and other Chapter VI-A deductions
  - a previous employer's salary and TDS

## Part B computation (`App\Services\Payroll\Form16`)

1. Gross salary = pay heads + leave encashment + previous employer's salary.
2. Section 10 exemptions are deducted (old regime only).
3. The standard deduction is taken off: ₹75,000 in the new regime, ₹50,000
   in the old.
4. Professional tax is taken off (old regime only, up to ₹2,500).
5. Income from house property: interest on a home loan, as a loss of up to
   ₹2 lakh (old regime only).
6. Other income is added.
7. Chapter VI-A deductions are taken off (old regime only): 80C up to ₹1.5
   lakh, 80CCD(1B) up to ₹50,000, and 80D and others as declared.
8. Taxable income is rounded to the nearest ₹10 (section 288A).
9. Slab tax:
   - New regime: 0–4 L nil, 5%, 10%, 15%, 20%, 25%, with 30% above ₹24 lakh.
   - Old regime: 0–2.5 L nil, 5%, 20%, 30%. The nil band rises to ₹3 lakh
     at age 60 and ₹5 lakh at age 80. Age is the age reached by 31 March,
     from the date of birth.
10. Section 87A rebate:
    - new regime: up to ₹60,000 when taxable income is ₹12 lakh or less,
      with marginal relief above that (the tax is no more than the income
      over ₹12 lakh)
    - old regime: up to ₹12,500 when taxable income is ₹5 lakh or less
11. Surcharge of 10/15/25% (and 37% in the old regime) above ₹50 lakh, ₹1
    crore and ₹2 crore (and ₹5 crore).
12. Cess of 4%. The total is rounded to ₹10 (section 288B).
13. TDS by this employer and the previous employer is taken off, leaving the
    balance payable or the excess deducted.

The rates are in `config/erp.php` under `income_tax` (Finance Act 2025, from
FY 2025-26). Review them each budget.

## Warnings shown

- The employee has no PAN (section 206AA).
- The company has no TAN.
- Old-regime amounts were declared under the new regime and are ignored.
- TDS is short of the tax.

## Manual checklist

1. Post three monthly payrolls for an employee with a TDS pay head. Form 16 lists them, and Q1 shows the salary and TDS.
2. Enter a declaration with the old regime, 80C above ₹1.5 lakh, and home loan interest above ₹2 lakh. Both amounts are capped in Part B.
3. Switch to the new regime. The deductions disappear, a warning lists them as ignored, and the standard deduction becomes ₹75,000.
4. Enter 24Q receipt numbers and download the PDF. It shows Part A with the receipts, Part B, and a verification block.
5. As a viewer, the list and PDF open, but the declaration and receipt forms do not.

## Done / partial / missing

- **Done:** Part A summary by quarter; declarations; both regimes with rebate, marginal relief, surcharge, cess and senior exemption; summary and CSV; PDF; permissions; audit of PDF printing; tests (`tests/Feature/Payroll/Form16Test.php`).
- **Partial:**
  - There is no marginal relief on surcharge.
  - 80D and other deductions are taken as declared, with no limits by age.
  - The HRA exemption is entered, not calculated from rent paid.
  - The form follows the Form 16 layout but is not the TRACES-generated Part A. It has no challan / BSR details.
- **Missing:** the 24Q return file (FVU); a monthly TDS projection to set the TDS pay head; Form 12BA (perquisites).
