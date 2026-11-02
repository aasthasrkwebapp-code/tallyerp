# Phase 10k: funds flow statement

## What it does

Reports → Funds Flow, for any period.

- **Sources of funds**:
  - a fall in a long-term asset, such as selling a fixed asset or getting back an investment
  - a rise in a long-term liability, such as a new loan or more capital
  - net profit for the period
- **Application of funds**:
  - a rise in a long-term asset, such as buying a fixed asset
  - a fall in a long-term liability, such as repaying a loan or drawings
  - net loss
- **Long-term** means every primary balance sheet group except Current Assets and Current Liabilities. This includes Fixed Assets, Investments, Capital, Loans, Suspense and Branch / Divisions.
- **Increase or decrease in working capital** is sources less applications.
- **Changes in working capital** lists each current asset and liability group, the ledgers placed directly under Current Assets or Current Liabilities, and closing stock. For each it shows the opening and closing balance and the effect on working capital.
- **Self-check**: the statement checks that sources less applications equals the change in working capital. If it doesn't, a red warning points to the integrity report.
- **Detailed** shows the ledgers that changed under each long-term group, and every name links to its ledger or group summary.
- Print, and export to CSV, Excel and PDF.

## Data

No new tables. The report uses opening and closing balances from posted vouchers and ledger openings, the period's P&L, and stock valuation.

## Permissions

| Action | Permission |
|---|---|
| View | `reports.view` |
| Export | `reports.export` |

## Tests

`tests/Feature/Reports/FundsFlowTest.php` (3 tests) covers:
- a worked example with a loan, a fixed asset purchase and profit, with every working capital row checked
- a loss with a loan repayment and stock
- the screen, exports and permissions

## Manual checklist

1. Post a loan received into the bank and a fixed asset bought from the bank.
2. Open Reports → Funds Flow for the period. The loan is a source, the asset is an application, and profit is a source.
3. The increase in working capital equals the total of the "Changes in working capital" table, and there is no red warning.
4. Press Detailed. The loan and asset ledgers appear under their groups.
5. Export to CSV, Excel and PDF.
6. At 375 px, there is no sideways scroll.

## Done / partial / missing

- Done: funds flow with sources, applications, the working capital schedule, the self-check, detail and exports.
- Missing: separating funds from operations (profit before depreciation) from the net profit line, which needs a depreciation ledger type.
