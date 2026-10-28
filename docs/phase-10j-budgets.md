# Phase 10j: budgets and budget variance

## What it does

- **Budgets** (Reports → Budgets & Variance): a named plan for a period of up to one year, with lines for groups or ledgers.
  - Each line can be limited to one cost centre. Its sub-cost-centres count too.
  - Each line has a basis:
    - **Transactions in the period**: what is booked during the budget, such as rent, sales or travel.
    - **Closing balance**: the balance on the report date, such as a bank balance or a debtors limit. This can't be used with a cost centre.
  - Amounts are entered as positive numbers on the natural side: debit for expenses and assets, credit for income and liabilities.
  - The same group or ledger can appear once for each cost centre. Blank rows are skipped.
  - A group line includes all of its sub-groups and their ledgers.
- **Variance report** (open a budget):
  - Actuals run up to a date you choose. It defaults to today while the budget is running, and it is kept within the budget's dates.
  - **Budget to date** shares a transactions budget out by days. For example, 36,500 for a year is 6,100 after 61 days. A closing balance budget is not shared out.
  - **Variance** is actual minus budget to date. "% used" has a progress bar.
  - Expenses above budget are marked **Over budget**, and income below budget is marked **Short of target**. A banner counts both.
  - Only posted vouchers count, so drafts and cancelled vouchers are left out.
  - Names link to the ledger or group summary for the same dates.
  - Print, and export to CSV, Excel and PDF.
- **Copy to next year** makes a copy with the dates moved on one year, then opens it so you can adjust the amounts. It is logged in the audit trail.

## Data

- New tables: `budgets` (name unique per company, from, to, description) and `budget_lines` (group or ledger, optional cost centre, basis, amount).
- A ledger used in a budget cannot be deleted. Delete the line or the budget first.
- Deleting an empty group or cost centre also removes its budget lines.
- Backups and company deletion include both tables.
- Migration: `2026_10_28_000800_create_budgets_tables`. It rolls back cleanly.

## Permissions

| Action | Permission |
|---|---|
| List budgets and see the variance report | `reports.view` |
| Export the report | `reports.export` |
| Create or copy a budget | `masters.create` |
| Alter a budget | `masters.edit` |
| Delete a budget | `masters.delete` |

## Tests

`tests/Feature/Reports/BudgetTest.php` (5 tests) covers:
- pro-rata budgets, ledger and group lines, cost centres with sub-cost-centres, and closing balance lines
- over and short flags, and report dates kept inside the budget
- cancelled vouchers being left out
- validation: duplicates, closing balance with a cost centre, bad amounts, other companies' ledgers, and periods over a year
- the screens, export, alter, copy and delete
- permissions and company isolation

## Manual checklist

1. Reports → Budgets & Variance → New budget. Add Rent at 1,20,000, Sales Accounts at 50,00,000, and the bank ledger at 2,00,000 on closing balance. Save.
2. The report shows each line's budget to date for the days so far. Change "Actuals up to" and the figures move.
3. Post a rent payment that takes rent over the budget to date. The line turns red and says Over budget.
4. Add a line for a cost centre. Only entries tagged to that cost centre (or its sub-centres) count.
5. Press Copy to next year. A copy for the next year opens for editing.
6. Try to delete the Rent ledger. It is refused while a budget uses it.
7. Sign in as a viewer. You can see the report but there is no Alter, Copy or Delete.
8. At 375 px, the list, form and report have no sideways scroll.

## Done / partial / missing

- Done: budgets on groups and ledgers, by cost centre, on transactions or closing balance, the variance report with pro-rata budget to date and flags, exports, and copy to next year.
- Missing: month-by-month phasing (seasonal budgets), budget against actual on the P&L layout, and budgets for stock items.
