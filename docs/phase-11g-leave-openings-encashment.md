# 11g Opening leave balances and leave encashment

## What it does

- **Payroll → Leave register → Opening balances** (`/payroll/leave-openings`): a grid of
  employees and leave types with a yearly allowance, to enter each employee's unused leave
  when the books began (whole or half days).
- The leave register carries the opening balance into the books' first year. For a
  carry-forward type the balance then rolls on each year up to the type's limit; for other
  types it counts in the first year only. The "Carried in" column shows for carry-forward
  types and for types with opening balances.
- **Leave encashment** (`/payroll/leave-encashments`, in the menu): pick an employee and date
  to see their balances, then encash days of a paid leave type at a rate per day (suggested:
  monthly basic ÷ 30). It posts a payment voucher (Dr Salaries & Wages, Cr the cash or bank
  ledger) and the days come off that year's balance and what carries forward. An "Encashed"
  column appears in the register (and its exports) once any encashment exists.
- Cancelling an encashment (with a reason) cancels the voucher and restores the balance.
- Checks: active employee, paid leave type with an allowance, days in halves, not more than
  the balance on the date, rate above zero, date in an open financial year and after joining.
- Employees and leave types with encashments cannot be deleted. Both tables are in company
  backup and deletion, and the ledgers used are protected from deletion.

## Permissions

- View `payroll.view`; save openings `payroll.edit`; encash `payroll.create`; cancel `payroll.delete`.

## Files

- Migration `2026_10_28_002600_create_leave_openings_and_encashments.php`; models
  `LeaveOpening`, `LeaveEncashment`.
- `App\Services\Payroll\LeaveService`; `AttendanceService::leaveRegister()` / `carriedIn()` /
  `leaveBalance()`; `LeaveController`; views in `payroll/leave/`.
- Tests: `tests/Feature/Payroll/LeaveOpeningEncashmentTest.php` (3).

## Manual checklist

1. Set Earned Leave to carry forward (limit 30). Enter an opening of 10 days for an employee.
2. Leave register for the first year shows 10 carried in and 25 allowed.
3. Encash 5 days at the suggested rate: a payment voucher is posted; the register shows 5 encashed.
4. Try to encash more than the balance: refused.
5. Cancel the encashment: the voucher is cancelled and the balance is back.

## Done / partial / missing

- Done: opening balances, encashment with voucher, register and exports, guards, tests.
- Missing: encashment through the payslip (as an earning) and its tax treatment.
