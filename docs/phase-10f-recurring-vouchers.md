# Phase 10f: recurring vouchers

## What it does

- **Transactions → Recurring Vouchers** holds voucher templates that repeat, such as rent, loan EMIs, AMC fees and monthly provisions.
- **Make recurring** on any voucher page copies that voucher's type, lines, narration and reference into a new template.
  - Its first date is one month after the voucher.
  - A new bill reference becomes "new bill with the voucher's own number", and its due date becomes "due in N days".
  - Settlements of older bills become "on account".
- **Schedule**: weekly, monthly, quarterly, half-yearly or yearly, from a first date to an optional last date.
  - Monthly and longer schedules keep the day of the month. A 31st falls back to the last day of shorter months (31 May → 30 Jun → 31 Jul).
- **Each voucher** is either posted (or sent for approval when maker-checker is on and the user cannot approve), or saved as a draft for review.
  - Vouchers take the next number of their type.
  - The voucher page says "Made by the recurring voucher …".
  - Each one is written to the audit trail as `generated`.
- **When vouchers are made**:
  - Every day at 00:30 the scheduler runs `php artisan erp:recurring-vouchers`, which needs the usual `schedule:run` cron entry.
  - **Run due now** on the list, or **Make due vouchers now** on a template, does the same at once.
  - Missed dates are caught up, at most 36 per template per run.
  - Vouchers are made in the name of the person who set up the template, with their rights. If they have left the company, the company owner's name is used.
- **Failures are not skipped.** If a date cannot be posted (a locked period, no financial year, an inactive ledger), the run log records the reason and the template stays due on that date.
  - The template page shows the error. Fix the cause, or alter the next date.
- **Run log**: each date, the voucher made (with its current status) or the error, when it ran, and who ran it.
- **Alter** a template to change its lines, amounts, schedule or next date, or to pause it. **Delete** a template and the vouchers it already made are kept.

## Rules

| Rule | Where |
|---|---|
| The lines are checked like a posted voucher when the template is saved: balance, ledger rules, bill-wise and cost centres. | RecurringVoucherService::dryRun (makes a voucher and rolls it back) |
| Only voucher types with automatic numbering can be used. | RecurringVoucherService::save |
| The name is unique within the company. The end date cannot be before the start date. | RecurringVoucherController::input |
| A ledger used in a template cannot be deleted. | `erp.ledger_usage` → `recurring_voucher_lines` |
| Permissions: viewing needs `vouchers.view`. Creating, altering and running need `vouchers.create`. Deleting needs `vouchers.delete`. | routes |

## Data

- Tables: `recurring_vouchers`, `recurring_voucher_lines` and `recurring_voucher_runs`, all company-scoped.
- Company backups and deletion include them through `erp.deletion_tables`.
- Migration: `2026_10_28_000500_create_recurring_vouchers_tables`. It rolls back cleanly.

## Tests

`tests/Feature/Accounting/RecurringVoucherTest.php` (6 tests) covers:
- catching up month-end dates, bill references with the voucher's own number and a due date, the audit trail and the run log
- unbalanced templates being refused
- a locked period failing, then retrying after the next date is moved
- draft mode, the end date and pausing
- Make recurring from a voucher, and the scheduled command
- permissions, and the ledger-in-use check

## Manual checklist

1. Open a posted journal and press **Make recurring**. Name it, pick monthly and a first date in the past, then Save.
2. Press **Make due vouchers now**. One voucher is made for each missed date, and the run log lists them.
3. Lock an accounting period and set the next date inside it. Run it: the date shows as failed with the reason, and the next date does not move.
4. Pause the template. Run due now does nothing for it.
5. At 375 px, the form, the template page and the list have no sideways scroll.

## Done / partial / missing

- Done: recurring vouchers for every auto-numbered voucher type, with a schedule, a daily run, catch-up, a failure log, draft or post mode, and making one from a voucher.
- Missing:
  - Recurring sales invoices (invoices with items).
  - Changing the amount for each period, for example a reducing EMI interest split.
  - Email reminders before a voucher is made.
