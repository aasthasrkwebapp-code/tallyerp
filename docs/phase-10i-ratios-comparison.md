# Phase 10i: ratio analysis and period comparison

## What it does

- **Ratio analysis** (Reports → Ratio Analysis), laid out like Tally's.
  - **Principal groups**: working capital, current assets (with closing stock), current liabilities, cash, bank, bank OD, sundry debtors and creditors, closing stock, loans, net worth, sales, purchases, gross profit and net profit.
  - **Principal ratios**:

    | Ratio | Worked out as |
    |---|---|
    | Current ratio | Current assets ÷ current liabilities |
    | Quick ratio | (Current assets − stock) ÷ current liabilities |
    | Debt / equity | Loans (liability, including bank OD) ÷ net worth |
    | Gross profit % | Gross profit ÷ sales × 100 |
    | Net profit % | Net profit ÷ sales × 100 |
    | Operating cost % | (Sales − net profit) ÷ sales × 100 |
    | Receivable days | Sundry debtors ÷ sales × days in the period |
    | Payable days | Sundry creditors ÷ purchases × days in the period |
    | Inventory turnover | Cost of goods sold ÷ average of opening and closing stock |
    | Working capital turnover | Sales ÷ working capital |
    | Return on investment % | Net profit ÷ net worth × 100 |
    | Return on working capital % | Net profit ÷ working capital × 100 |

  - Balances are as on the "To" date. Sales, purchases and profit are for the period.
  - A ratio whose divisor is zero or negative shows "n/a" instead of a misleading number.
  - **Compare with the same period last year** adds a second column.
  - The figures come from the same P&L and balance sheet code, so they always agree with those reports. Groups are found by their place in the default chart of accounts, and sub-groups you add count under their parent.
- **Period comparison** (Reports → Period Comparison): the P&L or balance sheet with several periods side by side.
  - Column choices: this period against the same period last year, month by month, quarter by quarter (financial-year quarters), or financial year by year.
  - The balance sheet columns are as on the last day of each period.
  - Rows are matched by group or ledger across columns. A ledger used in only one period still shows 0 in the others.
  - **Detailed** shows the sub-groups and ledgers under each primary group. Every name links to the group summary or ledger.
  - With two columns, the change and change % are shown. A zero base shows "new" instead of a percentage.
  - With more than two P&L columns, a Total column is added. For stock, the total is the first opening stock and the last closing stock, not a sum.
  - Up to 12 columns. A longer span shows the first 12 with a note.
  - The particulars column stays in view when the table scrolls sideways on a phone.
- Both reports print and export to CSV, Excel and PDF.

## Data

No new tables or migrations. Both reports read posted vouchers, ledger opening balances and stock valuation.

## Permissions

| Action | Permission |
|---|---|
| View either report | `reports.view` |
| Export | `reports.export` |

## Tests

`tests/Feature/Reports/RatioComparisonTest.php` (7 tests) works every ratio out by hand on a small set of books. It covers:
- loans and bank OD as debt
- month columns with rows matched across periods, and the balance sheet balancing in every column
- stock totals
- quarter, year and previous-year columns, and the 12-column limit
- the screens and exports, and permissions

`PagesRenderTest` also opens both pages.

## Manual checklist

1. Reports → Ratio Analysis. Check that the current ratio equals current assets ÷ current liabilities shown on the left.
2. Tick "Compare with the same period last year". A second column appears.
3. Reports → Period Comparison → Profit & loss, Month by month. Each month's net profit matches the P&L for that month, and Total matches the P&L for the whole period.
4. Switch to Balance sheet, this period against last year. Total assets equals total liabilities in both columns, and the change column is filled in.
5. Press Detailed, then click a ledger name. Its ledger report opens for the period.
6. Export each report to CSV, Excel and PDF.
7. At 375 px, there is no page-level sideways scroll, and the particulars column stays put when you scroll the table.

## Done / partial / missing

- Done: ratio analysis with 12 ratios and a previous-year comparison, and P&L and balance sheet comparison by month, quarter, year or previous year with change, totals and exports.
- Missing: funds flow statement, budgets and budget variance, and ratios for a chosen custom group.
