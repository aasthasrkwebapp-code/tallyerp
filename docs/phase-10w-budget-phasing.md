# Phase 10w: Monthly budget phasing

## What it does
- **Budget → Monthly phasing** lists every line budgeted on **transactions** (closing-balance lines are not phased). Each line can be set to:
  - **Even by days** (the default, as before): the annual amount is spread by days. The month boxes show the resulting amounts, read-only.
  - **By month**: enter an amount for each month of the budget. Use it for seasonal sales, festival months or one-off costs. Months are calendar months cut to the budget's dates, so a budget from 15 April starts with "Apr" covering 15 to 30 April.
- The page totals the months as you type. The total turns red when it differs from the current budget. **Saving sets the line's annual budget to the total of its months.**
- **Budget to date for a phased line** = the months before the report date's month, plus that month's amount × days elapsed ÷ days in the month. Variance, % used and the over-budget / short-of-target flags then follow as before.
- The budget page shows a "Phased by month" badge on phased lines.
- **Altering the budget** keeps a line's phasing when its group or ledger, cost centre, basis and amount are unchanged. Changing the amount, or the dates so the months differ, drops it (back to even), so the months can't silently disagree with the budget.
- **Copy to next year** carries the phasing over.
- Saving phasing is written to the audit trail.

## Data
Migration `2026_10_28_001700_add_phasing_to_budget_lines` adds `budget_lines.phasing`, a JSON list of paise per month. It is null when the line is even.

## Permissions
| Action | Permission |
|---|---|
| See phased budgets in the report | `reports.view` |
| Open and save phasing | `masters.edit` |

## Tests
`tests/Feature/Reports/BudgetTest.php` has 2 new tests. They cover:
- the months of a budget and the even split
- saving months, with closing lines ignored and the amount set to the total
- budget to date at a month end, mid-month and before the first phased month
- a wrong month count
- phasing kept or dropped when the budget is altered, and carried to the next-year copy
- going back to even
- the screen and permissions

## Manual checklist
1. Make a budget of 36,500 for Rent for April to March. Open Monthly phasing and choose By month. Enter 0 for April, 10,000 for May and 2,650 for each month after.
2. Save. The budget page shows Phased, and as on 31 May the budget to date is 10,000.
3. Change May to 11,000. The total turns red. Save, and the annual budget becomes 37,500.
4. Alter the budget and change Rent's amount. Its phasing goes back to even.
5. At 375 px, the phasing page has no sideways scroll.

## Done / partial / missing
- Done: month-wise phasing with a live total, phased budget to date, carried to copies, and kept or dropped safely on edits.
- Missing:
  - a month-by-month budget vs actual table
  - phasing by quarter or by a percentage pattern applied to all lines
