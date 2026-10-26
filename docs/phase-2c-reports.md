# Phase 2c / 4 — Accounting reports and dashboard

## What was built

All reports are computed from **posted** voucher entries plus ledger opening balances.
Drafts, pending and cancelled vouchers are ignored. Code: `App\Services\Reports\AccountBalances`
and `App\Services\Reports\FinancialStatements`.

* **Ledger report** (`reports/ledger`): opening balance, every posting with running
  Dr/Cr balance, period totals and closing balance. Links to the voucher.
* **Cash book / Bank book**: the ledger report restricted to cash or bank ledgers
  (`?book=cash` / `?book=bank`).
* **Day book**: all posted vouchers in a period, filterable by voucher type.
* **Trial balance**: opening, period debit, period credit and closing per ledger, grouped
  by account group, with totals that must agree. The opening-balance difference (if the
  ledger openings do not net to zero) is shown as a separate row instead of hiding it.
* **Group summary**: drill from a group into its sub-groups and ledgers.
* **Profit & loss**: trading section (direct income/expense → gross profit) and P&L section
  (indirect income/expense → net profit), following group nature and the gross-profit flag.
* **Balance sheet**: liabilities and assets by group, with the current-period profit and the
  profit accumulated in earlier years carried into capital. Income and expense ledgers
  reset at each financial-year start. Any difference is shown, not plugged.
* **CSV export** on every report (needs `reports.export`); cells are guarded against
  spreadsheet formula injection.
* **Dashboard**: cash and bank balance, receivables, payables, sales, purchases, income,
  expenses and profit for the year, a monthly sales/purchase table, and recent vouchers.
* All reports are responsive: tables become cards on phones, including totals rows.

## Tests

`tests/Feature/Reports/FinancialReportsTest.php` posts a hand-computed scenario (capital,
purchases, sales, expenses, receipts, payments) and asserts gross profit 20,000, net profit
16,000 and a balance sheet total of 1,46,000; it also checks that cancelled/draft vouchers
are excluded, FY carry-forward, CSV export and permission checks.

## Manual testing checklist

1. Post: Capital Cr 1,00,000 / Cash Dr; Purchase 50,000 from a supplier; Sales 70,000 to a
   customer; Rent paid 4,000 in cash.
2. **Trial Balance** totals agree; Dr = Cr.
3. **Profit & Loss**: gross profit 20,000, net profit 16,000.
4. **Balance Sheet**: both sides equal; profit 16,000 appears under capital.
5. Cancel the rent voucher → net profit becomes 20,000 on refresh.
6. **Ledger Report** for the customer shows the sales entry and running balance.
7. **Cash / Bank Book** lists only cash and bank ledgers.
8. **Day Book** for today lists all vouchers; filter by *Payment*.
9. Click **CSV** on any report → file downloads (hidden for users without export permission).
10. Open the dashboard on a phone: no horizontal scrolling.

## Known limitations

* Closing stock is not valued automatically yet (needs the inventory module); the P&L shows
  a note.
* Opening bill-wise references for ledgers are not supported yet (openings are on account).
* PDF and Excel export are pending (Phase 8); CSV works.
