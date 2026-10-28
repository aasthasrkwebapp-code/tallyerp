# Phase 10z: Batch cheque printing

## What it does
- The **cheque register** has a tick box on every issued cheque that can be printed, meaning one that is not cancelled. Received cheques, drafts/transfers and spoilt leaves have no tick box.
  - **Tick all** ticks every printable row in the current filter.
  - **Print ticked cheques** opens one print page with a leaf per cheque, each on its own page. Leaves are sorted by bank and cheque number. Each leaf uses its cheque book's layout, as single printing does.
- On screen, a label above each leaf shows its number, payee and amount. The label is not printed.
- A new **Printed** filter shows cheques that are **Not yet** printed or already **Printed**, so the day's cheques can be found and printed in one go.
- Every print, single or batch, stamps the cheque with when it was printed and adds to a count. The register shows "printed dd-mm-yyyy (n×)", and the CSV export has a Printed column. Each print is written to the audit trail, as "reprinted" from the second time.
- **Reprinting** is allowed. The print page then names the cheques printed before and reminds you to mark the earlier leaf spoilt if it was not used.
- The leaves of one print job must share one cheque size, because the printer's page size is set once. Cheques from books with different sizes are refused with a message to print one bank or book at a time.
- At most 100 cheques can be printed at once.

## Data
Migration `2026_10_28_002000_add_cheque_printed_to_vouchers` adds `vouchers.cheque_printed_at` and `cheque_print_count`.

## Permissions
| Action | Permission |
|---|---|
| Print one or many cheques | `banking.view`, as single cheque printing already needs |

## Tests
`tests/Feature/Banking/ChequeRegisterTest.php` has a new test, `test_batch_printing_marks_cheques_printed`. It covers:
- which rows get a tick box
- the batch page in cheque number order, with cancelled and received cheques left out
- the printed stamp and count
- the reprint warning
- the Printed filter and the CSV column
- nothing printable, and nothing ticked
- mixed cheque sizes refused

## Manual checklist
1. Enter three payment vouchers with cheques from one bank.
2. In the cheque register, choose Printed: Not yet, tick all and press Print ticked cheques. Three leaves appear in number order. Print preview shows one per page at the cheque size.
3. Back in the register, the cheques show "printed" with today's date, and Not yet no longer lists them.
4. Print one of them again. The page warns that it was printed before.
5. At 375 px, the register's tick boxes and button have no sideways scroll.

## Done / partial / missing
- Done: batch printing from the register, printed stamps, the reprint warning and the filter.
- Missing:
  - printing on continuous cheque stationery with several leaves per sheet
  - positive pay files for banks
