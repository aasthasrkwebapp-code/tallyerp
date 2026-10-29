# Phase 10x: Attendance import and leave carry-forward

## What it does

### Attendance import
**Payroll → Attendance → Import** loads attendance from a .csv or .xlsx file. The file is read and shown as a preview first, and nothing is saved until **Import** is pressed. Two layouts are accepted:
- **A list**, one row per employee and day, as a biometric or HR system exports it. The columns are `Employee code`, `Date` and `Status`, and optionally `Overtime hours`.
  - Rows can cover several months.
  - An employee's overtime in a month is added up and replaces that month's overtime.
  - Dates can be YYYY-MM-DD, DD-MM-YYYY, DD/MM/YYYY, DD.MM.YYYY or Excel dates.
  - A CSV and an Excel template can be downloaded.
- **The register grid**, as the register's own CSV/Excel export lays it out. The columns are `Code`, one per day (1 to 31) and optionally `Overtime hours`, for the month chosen on upload.
  - Summary columns are ignored.
  - A blank cell leaves the day unchanged.
  - Export → edit in Excel → import works as a round trip.

Status values:
- Register codes: P, A, ½ or HD, W, H.
- A leave code such as CL, or ½CL (also 1/2CL, CL/2) for half a day on leave.
- Words: Present, Absent, Half day, Weekly off, Holiday.
- Codes and words are not case-sensitive.

Days in the file replace what the register has for those days. The employee's other days in the month are kept. A day marked P removes an earlier mark.

The file is checked in full. If any row has a problem, nothing is imported, and every problem is listed with its row number. The checks are:
- an unknown or inactive employee code
- a bad date
- an unknown status or inactive leave code
- a day off the employee's rolls (before joining or after leaving)
- the same day twice with different entries
- overtime that is not hours, or more than 400 in a month
- a grid day that the month does not have

What is imported goes through the same save as the register screen, so the same rules apply.

### Leave carry-forward
- A leave type with **days a year** can be set to **carry unused days to next year**, with an optional **most days carried** limit.
- For such a type, the leave register's allowance for a year = days a year + days carried in.
- Days carried in = last year's balance, capped at the limit. An overdrawn year carries nothing.
- The chain runs year by year from the financial year of the books' beginning, or the employee's joining year if later.
- The register shows a **Carried in** column for these types. The CSV/Excel/PDF exports add "<code> carried in".
- Carry-forward can't be set on leave without a yearly allowance. Unticking it clears the limit.

## Data
Migration `2026_10_28_001800_add_carry_forward_to_leave_types` adds `leave_types.carry_forward` (boolean, default false) and `max_carry_forward` (decimal 5,1, null = no limit). Existing leave types are unchanged, so nothing carries forward until it is turned on.

## Permissions
| Action | Permission |
|---|---|
| Import screen, template, preview and import | `payroll.edit` |
| Leave register with carried-in days | `payroll.view` |
| Set carry-forward on a leave type | `payroll.edit` (`payroll.create` for a new type) |

## Tests
`tests/Feature/Payroll/AttendanceImportTest.php` (5 tests) covers:
- carry-forward over two earlier years, the limit, an overdrawn year, a late joiner, the screen and CSV
- the leave type settings and their validation
- a list import across two months: case-insensitive codes, half-day leave, overtime summed, days not in the file kept, the preview's payload being what is saved
- every kind of row problem blocking the import, and a file with neither layout
- the register layout with ignored summary columns, paid days after import, day 31 in a 30-day month, the month being required, the template, the link, and permissions

The leave register test in `AttendanceLoanTest` now expects the `carried` key.

## Manual checklist
1. Export the attendance register for a month to Excel. Change a few days to A, CL and ½CL, and set an overtime figure. Import it with the same month chosen. The preview lists the employees and entries. Import, and the register shows the changes.
2. Import a list CSV with a wrong employee code and a date twice. The preview lists both problems with row numbers and has no Import button.
3. Edit Earned Leave: tick carry forward and set the most days to 30. With leave taken in an earlier year, the leave register shows the carried-in days and a larger allowance.
4. At 375 px, the import and preview pages have no sideways scroll.

## Done / partial / missing
- Done:
  - attendance import from CSV/Excel in list or register layout, with a full preview and all-or-nothing checks
  - leave carry-forward with a limit, shown in the leave register and its exports
- Partial: carry-forward gives a full year's allowance in the joining year; it is not pro-rated.
- Missing:
  - opening leave balances for employees moved from another system
  - leave encashment
  - in/out punch times, which would need a time-based import
