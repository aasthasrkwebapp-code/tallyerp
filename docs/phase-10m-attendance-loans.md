# Phase 10m: attendance, leave, overtime, and loans and advances

## What it does

### Attendance register
Payroll → Attendance shows one row per employee and one column per day of the month.
- A blank day counts as present. The other entries are:
  - A: absent
  - ½: half day, half absent
  - W: weekly off
  - H: holiday
  - a leave code, such as CL, SL, EL or LWP
  - ½ plus a leave code, such as ½CL: half a day on leave
- **Mark Sundays as weekly off** fills every blank Sunday. **Clear the month** empties the register.
- Days before an employee joined or after they left are greyed out and not stored.
- **OT hours** are entered as a total for the month.
- **Paid days** = days on the rolls − absent days − ½ for each half day − leave without pay. Paid leave, weekly offs and holidays are paid. Hover over the figure to see present, absent, leave and off days.
- Exports to CSV, Excel and PDF.
- The register sends one field per employee, so large companies stay under PHP's `max_input_vars` limit.

### Leave types and leave register
- Default leave types:
  - Casual Leave (CL, 12 days a year)
  - Sick Leave (SL, 12)
  - Earned Leave (EL, 15)
  - Leave Without Pay (LWP, unpaid, no limit)
- Leave types can be added or changed.
- Once a leave type has been used, it can't be switched between paid and unpaid, and it can't be deleted.
- **Leave register**: for each employee and leave type in the financial year, it shows the allowance, the leave taken and the balance. More leave than allowed shows in red. Unused leave doesn't carry forward.

### Payroll
- **Preparing a month**:
  - If the employee has attendance marked that month, paid days come from the register. Otherwise paid days are the days on the rolls, as before.
  - A calendar icon on the pay sheet marks paid days taken from attendance.
- **Take paid days from attendance** on a draft run reloads paid days for every payslip. Paid days can still be typed in by hand.
- **Overtime** is a new earning pay head. Its amount is overtime hours × the hourly rate set in the employee's salary structure. It isn't reduced for unpaid days. The payslip shows the hours.
- **Loan / Advance Recovery** is a new deduction pay head. It isn't in the salary structure; it is filled from the employee's loans.

### Loans and advances
- Payroll → Loans & Advances → **New** records a loan or a salary advance with:
  - the amount and monthly instalment
  - the first payroll month to recover it in
  - the cash or bank ledger it was paid from
  - the loan ledger (default **Salary Advances & Loans to Employees**, under Loans & Advances (Asset))
- **Saving posts a payment voucher**: Dr the loan ledger, Cr cash or bank.
- **Each payroll month** deducts the instalment, or what is left of the loan if that is less. Older loans are recovered first.
  - The recovery is limited to the net pay, so net pay never goes below zero.
  - Posting the payroll credits each loan's ledger.
- **Only posted payroll counts as recovered.**
  - Cancelling a payroll puts its recovery back.
  - If two draft months both recover the same instalment, the second one can't be posted until it is recalculated.
- **A loan can be cancelled** (with its voucher) only while no draft or posted payroll recovers it.
- **Pages**:
  - the loan list shows each loan's amount, recovered and outstanding, filtered by status (outstanding, repaid, cancelled, all) or employee
  - the loan page lists every recovery and the running balance
  - the employee page shows their outstanding loans

## Data

Migration `2026_10_28_001000_create_attendance_and_loans_tables`:
- New tables: `leave_types`, `attendance_days` (one row per marked day), `attendance_months` (overtime hours), `employee_loans` and `loan_recoveries` (payslip ↔ loan).
- `payslips` gets `overtime_hours` and `from_attendance`.
- For every company it adds:
  - the Salary Advances ledger
  - the Overtime and Loan / Advance Recovery pay heads
  - the four leave types

## Permissions

| Action | Permission |
|---|---|
| View the attendance register, leave register, leave types and loans, and export them | `payroll.view` |
| Save attendance, take paid days from attendance, edit leave types | `payroll.edit` |
| New loan, new leave type | `payroll.create` |
| Cancel a loan, delete a leave type | `payroll.delete` |

## Tests

`tests/Feature/Payroll/AttendanceLoanTest.php` (8 tests) covers:
- seeded defaults
- paid days and summaries from every status, including a mid-month joiner
- overtime with and without marked days
- the leave register
- a loan paid and recovered over three months, with the vouchers checked
- recovery capped at net pay and the re-check of two drafts at posting
- loan validation and cancellation
- the screens, exports and permissions

## Manual checklist

1. Open Payroll → Attendance for a month. Press **Mark Sundays as weekly off**, mark two absent days and one CL day, enter 6 OT hours, and save. Paid days show 29 for a 31-day month.
2. Open Payroll → Pay Heads. Overtime and Loan / Advance Recovery are listed. Edit an employee and give Overtime an hourly rate.
3. Prepare payroll for the month. The employee has 29 paid days with a calendar icon, and an overtime line of hours × rate.
4. Open Payroll → Loans & Advances → New. Give an advance of 5,000 at 2,000 a month from this month, paid from Cash. The payment voucher debits Salary Advances.
5. Recalculate the draft payroll. Loan recovery is 2,000 and net pay falls by 2,000. Post it. The journal credits Salary Advances with 2,000.
6. Open the loan: it shows 2,000 recovered and 3,000 outstanding.
7. Try to cancel the loan: this is refused while payroll recovers it.
8. Open the leave register: CL shows 1 taken and 11 balance.
9. At 375 px, the attendance grid scrolls inside its card and the page has no sideways scroll.

## Done / partial / missing

- Done:
  - the monthly attendance register with leave types
  - paid days from attendance
  - overtime
  - the leave register with allowances
  - loans and advances with payroll recovery and postings
- Missing:
  - attendance import from a biometric device or CSV
  - daily overtime and shift timings
  - leave carry-forward and encashment
  - interest on loans
  - editing a loan, and skipping or changing an instalment for a single month
